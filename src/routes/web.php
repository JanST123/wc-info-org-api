<?php

use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\PlaceController;
use App\Http\Controllers\Api\SitemapController;
use App\Http\Controllers\Api\ToiletController;
use App\Http\Controllers\Api\UploadController;
use Illuminate\Support\Facades\Route;

Route::get('toilets/place/{placeId}', [ToiletController::class, 'forPlace']);
Route::get('toilets/bounds/{south}/{west}/{north}/{east}', [ToiletController::class, 'forBounds']);
Route::get('toilets/nearby/{lat}/{lon}', [ToiletController::class, 'nearby']);
Route::get('toilet/{id}/admin-qualify', [ToiletController::class, 'adminQualify']);
Route::get('toilet/{id}/admin-delete', [ToiletController::class, 'adminDelete']);
Route::get('toilet/{id}', [ToiletController::class, 'byId']);
Route::patch('toilet/{id}/update', [ToiletController::class, 'update']);
Route::post('toilet/add', [ToiletController::class, 'add']);
Route::post('toilet/add-properties/{toiletId}', [ToiletController::class, 'addProperties']);

Route::post('places/{placeId}', [PlaceController::class, 'store']);
Route::get('places/{placeId}', [PlaceController::class, 'show']);

Route::post('upload', [UploadController::class, 'uploadFile']);
Route::post('uploadSubmit/{toiletId}', [UploadController::class, 'submitPhotos']);
Route::delete('deletePhoto/{toiletId}/{filename}', [UploadController::class, 'deletePhoto']);

Route::get('sitemap', [SitemapController::class, 'output']);
Route::get('health', [HealthController::class, 'health']);

Route::view('docs/api/swagger', 'docs.swagger');
