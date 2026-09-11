<?php

use App\Http\Controllers\Admin\AdminAuthController;
use App\Http\Controllers\Admin\AdminToiletController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\PlaceController;
use App\Http\Controllers\Api\SitemapController;
use App\Http\Controllers\Api\ToiletController;
use App\Http\Controllers\Api\UploadController;
use Illuminate\Support\Facades\Route;

// Public Toilet API routes
Route::get('toilets/place/{placeId}', [ToiletController::class, 'forPlace']);
Route::get('toilets/bounds/{south}/{west}/{north}/{east}', [ToiletController::class, 'forBounds']);
Route::get('toilets/nearby/{lat}/{lon}', [ToiletController::class, 'nearby']);
Route::get('toilet/{id}', [ToiletController::class, 'byId']);
Route::patch('toilet/{id}/update', [ToiletController::class, 'update']);
Route::post('toilet/add', [ToiletController::class, 'add']);
Route::post('toilet/add-properties/{toiletId}', [ToiletController::class, 'addProperties']);

// Public Place cache routes
Route::post('places/{placeId}', [PlaceController::class, 'store']);
Route::get('places/{placeId}', [PlaceController::class, 'show']);

// Public Photo upload routes
Route::post('upload', [UploadController::class, 'uploadFile']);
Route::post('uploadSubmit/{toiletId}', [UploadController::class, 'submitPhotos']);
Route::delete('deletePhoto/{toiletId}/{filename}', [UploadController::class, 'deletePhoto']);

// Utility routes
Route::get('sitemap', [SitemapController::class, 'output']);
Route::get('health', [HealthController::class, 'health']);
Route::view('docs/api/swagger', 'docs.swagger');

// Admin Authentication routes
Route::get('admin/login', [AdminAuthController::class, 'showLogin'])->name('admin.login');
Route::post('admin/login', [AdminAuthController::class, 'login']);
Route::post('admin/logout', [AdminAuthController::class, 'logout'])->name('admin.logout');

// Protected Admin Panel routes
Route::middleware('admin.auth')->prefix('admin')->name('admin.')->group(function () {
    Route::get('/', [AdminToiletController::class, 'index'])->name('index');
    Route::get('costs', [AdminToiletController::class, 'costs'])->name('costs');
    Route::post('costs/budget', [AdminToiletController::class, 'updateBudget'])->name('costs.budget');
    Route::get('toilets', [AdminToiletController::class, 'index'])->name('toilets.index');
    Route::post('toilets/find', [AdminToiletController::class, 'find'])->name('toilets.find');
    Route::get('toilets/{id}', [AdminToiletController::class, 'show'])->name('toilets.show');
    Route::post('toilets/{id}', [AdminToiletController::class, 'update'])->name('toilets.update');
    Route::get('toilets/{id}/nearby-places', [AdminToiletController::class, 'getNearbyPlaces'])->name('toilets.nearby-places');
    Route::post('toilets/{id}/assign-place', [AdminToiletController::class, 'assignPlace'])->name('toilets.assign-place');
    Route::post('toilets/{id}/unflag', [AdminToiletController::class, 'unflag'])->name('toilets.unflag');
    Route::post('toilets/{id}/toggle-flag', [AdminToiletController::class, 'toggleFlag'])->name('toilets.toggle-flag');
    Route::post('toilets/{id}/restore-version/{version}', [AdminToiletController::class, 'restoreVersion'])->name('toilets.restore-version');
    Route::post('toilets/{id}/reschedule-discovery', [AdminToiletController::class, 'rescheduleDiscovery'])->name('toilets.reschedule-discovery');
    Route::post('toilets/{id}/photos/{filename}/delete', [AdminToiletController::class, 'deletePhoto'])->name('toilets.photos.delete');
    Route::delete('toilets/{id}/photos/{filename}', [AdminToiletController::class, 'deletePhoto']);
});
