<?php

use App\Http\Controllers\HostReservationController;
use App\Http\Controllers\OfficeController;
use App\Http\Controllers\OfficeImageController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\TagController;
use App\Http\Controllers\UserReservationController;

Route::get('/tags', TagController::class);

// Offices
Route::get('/offices', [ OfficeController::class, 'index' ]);
Route::get('/offices/{office}', [ OfficeController::class, 'show' ]);
Route::post('/offices', [ OfficeController::class, 'create' ])->middleware(['auth:sanctum', 'verified']);
Route::put('/offices/{office}', [ OfficeController::class, 'update' ])->middleware(['auth:sanctum', 'verified']);
Route::delete('/offices/{office}', [ OfficeController::class, 'delete' ])->middleware(['auth:sanctum', 'verified']);
Route::post('/offices/{office}/images', [ OfficeImageController::class, 'store' ])->middleware(['auth:sanctum', 'verified']);
Route::delete('/offices/{office}/images/{image:id}', [ OfficeImageController::class, 'delete' ])->middleware(['auth:sanctum', 'verified']);

// User reservations...
Route::get('/reservations', [ UserReservationController::class, 'index' ])->middleware(['auth:sanctum', 'verified']);
Route::post('/reservations', [ UserReservationController::class, 'create' ])->middleware(['auth:sanctum', 'verified']);
Route::delete('/reservations/{reservation}', [ UserReservationController::class, 'cancel' ])->middleware(['auth:sanctum', 'verified']);

// User reservations...
Route::get('/host/reservations', [ HostReservationController::class, 'index' ]);
