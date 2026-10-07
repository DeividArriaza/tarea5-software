<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    use ApiResponse;

    // Bcrypt (el hasher efectivo de este proyecto, via bcrypt() en
    // User::setPasswordAttribute) trunca en silencio cualquier byte extra
    // arriba de 72. Sin este tope, dos contrasenas distintas que compartan
    // los primeros 72 bytes producirian el mismo hash.
    private const BCRYPT_MAX_BYTES = 72;

    public function register(Request $request): JsonResponse
    {
        // Recorta el correo ANTES de validar el formato: la regla `email`
        // rechaza espacios, asi que un correo pegado con espacios (copiado
        // desde otra app) fallaria la validacion en vez de normalizarse.
        $request->merge(['email' => trim((string) $request->input('email'))]);

        $validated = $request->validate([
            'name'     => 'required|string|max:255',
            'email'    => 'required|string|email|max:255',
            'password' => ['required', 'string', 'min:6', function ($attribute, $value, $fail) {
                if (strlen($value) > self::BCRYPT_MAX_BYTES) {
                    $fail('La contrasena no debe superar los 72 bytes.');
                }
            }],
            'role'     => 'required|in:cliente,proveedor',
        ]);

        $email = mb_strtolower($validated['email'], 'UTF-8');

        // Unicidad case-insensitive: el UNIQUE de la columna en Postgres es
        // sensible a mayusculas, asi que "Foo@x.com" y "foo@x.com" no
        // chocarian con `unique:users,email`. Se detecta la colision aqui
        // sin fusionar ni migrar cuentas existentes.
        $existe = User::whereRaw('LOWER(email) = ?', [$email])->exists();
        if ($existe) {
            throw ValidationException::withMessages([
                'email' => ['El correo electronico ya esta registrado.'],
            ]);
        }

        $validated['email'] = $email;
        $validated['name']  = trim($validated['name']);

        $user  = User::create($validated);
        $token = $user->createToken('auth-token')->plainTextToken;

        return $this->success('Usuario registrado exitosamente', [
            'user'  => $user,
            'token' => $token,
        ], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $request->merge(['email' => trim((string) $request->input('email'))]);

        $request->validate([
            'email'    => 'required|email',
            'password' => 'required|string',
        ], [
            'email.required'    => 'El correo electronico es obligatorio.',
            'email.email'       => 'Ingresa un correo electronico valido.',
            'password.required' => 'La contrasena es obligatoria.',
            'password.string'   => 'La contrasena no es valida.',
        ]);

        // Busqueda case-insensitive: preserva el acceso de cuentas
        // existentes cuyo correo se guardo con mayusculas antes de esta
        // normalizacion, sin imponerles retroactivamente la politica de
        // registro.
        $email = mb_strtolower((string) $request->input('email'), 'UTF-8');
        $user  = User::whereRaw('LOWER(email) = ?', [$email])->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            return $this->error('Credenciales incorrectas', 401);
        }

        // Revocar tokens anteriores y crear uno nuevo
        $user->tokens()->delete();
        $token = $user->createToken('auth-token')->plainTextToken;

        return $this->success('Login exitoso', [
            'user'  => $user,
            'token' => $token,
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return $this->success('Sesion cerrada correctamente');
    }

    public function me(Request $request): JsonResponse
    {
        return $this->success('OK', ['user' => $request->user()]);
    }
}
