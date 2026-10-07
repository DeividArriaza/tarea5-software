<?php

namespace App\Http\Controllers;

use App\Models\Categoria;
use Illuminate\Http\JsonResponse;

class CategoriaController extends Controller
{
    public function index(): JsonResponse
    {
        $categorias = Categoria::orderBy('nombre')->get();

        return response()->json([
            'message'    => 'Lista de categorias',
            'categorias' => $categorias,
        ]);
    }
}
