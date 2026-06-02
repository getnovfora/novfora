<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::view('/spike', 'spike'); // Spike 0 — editor page

Route::post('/spike/upload', function (\Illuminate\Http\Request $request) {
    $request->validate(['file' => ['required', 'image', 'max:5120', 'mimes:png,jpg,jpeg,gif,webp']]);
    $file = $request->file('file');
    $name = \Illuminate\Support\Str::uuid()->toString() . '.' . strtolower($file->getClientOriginalExtension() ?: 'png');
    $file->move(public_path('spike-uploads'), $name);
    return response()->json(['url' => '/spike-uploads/' . $name]);
});
