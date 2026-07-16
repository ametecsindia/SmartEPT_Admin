<?php

use Illuminate\Support\Facades\Route;

// SmartEPT admin console (server-rendered shell + JS calling the JSON API).
Route::redirect('/', '/admin');
Route::view('/admin', 'admin');
