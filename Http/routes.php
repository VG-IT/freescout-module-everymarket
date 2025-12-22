<?php

Route::group(['middleware' => 'web', 'prefix' => \Helper::getSubdirectory(), 'namespace' => 'Modules\Everymarket\Http\Controllers'], function()
{
    Route::post('/everymarket/ajax', ['uses' => 'EverymarketController@ajax', 'laroute' => true])->name('everymarket.ajax');

    Route::get('/mailbox/everymarket/{id}', ['uses' => 'EverymarketController@mailboxSettings', 'middleware' => ['auth', 'roles'], 'roles' => ['admin']])->name('mailboxes.everymarket');
    Route::post('/mailbox/everymarket/{id}', ['uses' => 'EverymarketController@mailboxSettingsSave', 'middleware' => ['auth', 'roles'], 'roles' => ['admin']])->name('mailboxes.everymarket.save');

});
