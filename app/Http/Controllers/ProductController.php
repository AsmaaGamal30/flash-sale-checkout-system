<?php

namespace App\Http\Controllers;

use App\Http\Resources\ProductResource;
use App\Models\Product;
use Illuminate\Support\Facades\Log;

class ProductController extends Controller
{
    public function show($id)
    {
        $product = Product::findOrFail($id);
        return ProductResource::make($product);
    }
}
