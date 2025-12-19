<?php

namespace App\Http\Controllers;

use App\Models\Category;
use Illuminate\Http\Request;

class CategoryController extends Controller
{
    public function index(Request $request)
    {
        $items = Category::where(['active' => 1])->get();

        return response()->json(['success' => 'ok', 'items' => $items]);
    }


    public function store(Request $request)
    {
        sleep(1);

        $request->validate([
            'title' => 'required'
        ]);

        $user = $request->user();

        $family = new Category();
        $family->title = $request->title;
        $family->ico = $request->ico;
        $family->active = 1;
        $family->save();

        return response()->json(['success' => 'ok']);
    }
}
