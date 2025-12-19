<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ApiController extends Controller
{
    public function brand(Request $request)
    {
        $limit = $request->filled('limit') ? $request->limit : 100;

        $data = DB::table('dati')
            ->select('marca')
            ->orderBy('marca')
            ->groupBy('marca')
            ->paginate($limit);

        return response()->json([
            'status' => 'OK',
            'response' => $this->getData($data)
        ]);
    }


    public function filtri(Request $request)
    {
        $limit = $request->filled('limit') ? $request->limit : 100;

        $data = DB::table('dati')
            ->select('tipologia_filtro')
            ->orderBy('tipologia_filtro')
            ->groupBy('tipologia_filtro')
            ->paginate($limit);

        return response()->json([
            'status' => 'OK',
            'response' => $this->getData($data)
        ]);
    }


    public function prodotti(Request $request)
    {
        $limit = $request->filled('limit') ? $request->limit : 100;

        $data = DB::table('dati')
            ->select('marca as brand', 'modello', 'motore', 'codice_filtro', 'tipologia_filtro')
            ->when($request->brand, function ($query, $brand) {
                $query->where('marca', $brand); //$query->whereLike('marca', "%$brand%");
            })
            ->when($request->modello, function ($query, $modello) {
                $query->whereLike('modello', "%$modello%");
            })
            ->when($request->codice_filtro, function ($query, $codice_filtro) {
                $query->whereLike('codice_filtro', "%$codice_filtro%"); //$query->whereLike('marca', "%$brand%");
            })
            ->when($request->search, function ($query, $search) {
                $query->where(function ($query) use ($search) {
                    $query->orWhereLike('modello', "%$search%")
                        ->orWhereLike('motore', "%$search%");
                });
            })
            ->orderBy('id')
            ->paginate($limit);

        return response()->json([
            'status' => 'OK',
            'response' => $this->getData($data)
        ]);
    }

    private function getData($pagination)
    {
        return [
            'current_page' => $pagination->currentPage(),
            'from' => $pagination->firstItem(),
            'to' => $pagination->lastItem(),
            'per_page' => $pagination->perPage(),
            'total' => $pagination->total(),
            'data' => $pagination->items(),
        ];
    }
}
