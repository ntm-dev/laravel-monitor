<?php

use Illuminate\Support\Facades\Route;
use LaravelMonitor\Http\Controllers\Auth\EmailChangeController;
use LaravelMonitor\Http\Controllers\Auth\InvitationController;
use LaravelMonitor\Http\Controllers\Auth\LoginController;
use LaravelMonitor\Http\Controllers\Auth\OAuthController;
use LaravelMonitor\Http\Controllers\Auth\PasswordResetController;
use LaravelMonitor\Http\Controllers\Auth\SetupController;
use LaravelMonitor\Http\Controllers\Auth\TwoFactorChallengeController;
use LaravelMonitor\Http\Controllers\Auth\WebauthnController;
use LaravelMonitor\Http\Controllers\DashboardController;
use LaravelMonitor\Http\Controllers\JobAttemptController;
use LaravelMonitor\Http\Controllers\RequestDetailController;
use LaravelMonitor\Http\Controllers\SettingsController;
use LaravelMonitor\Http\Middleware\Authorize;
use LaravelMonitor\Http\Middleware\EnsureMonitorAuthenticated;

Route::domain(config('monitor.domain'))
    ->middleware(array_merge(config('monitor.middleware', ['web']), [Authorize::class]))
    ->prefix(config('monitor.path', 'monitor'))
    ->group(function () {
        Route::get('/setup', [SetupController::class, 'show'])->name('monitor.setup');
        Route::post('/setup', [SetupController::class, 'store'])->name('monitor.setup.store');
        Route::get('/login', [LoginController::class, 'show'])->name('monitor.login');
        Route::post('/login', [LoginController::class, 'store'])->middleware('throttle:10,1')->name('monitor.login.store');
        Route::post('/logout', [LoginController::class, 'destroy'])->name('monitor.logout');
        Route::get('/two-factor-challenge', [TwoFactorChallengeController::class, 'show'])->name('monitor.two-factor.challenge');
        Route::post('/two-factor-challenge', [TwoFactorChallengeController::class, 'store'])->middleware('throttle:10,1')->name('monitor.two-factor.challenge.store');
        Route::post('/webauthn/authenticate/options', [WebauthnController::class, 'authenticateOptions'])->name('monitor.webauthn.authenticate.options');
        Route::post('/webauthn/authenticate', [WebauthnController::class, 'authenticate'])->name('monitor.webauthn.authenticate.store');
        Route::get('/oauth/{provider}/redirect', [OAuthController::class, 'redirect'])->where('provider', 'google|apple')->name('monitor.oauth.redirect');
        Route::get('/oauth/{provider}/callback', [OAuthController::class, 'callback'])->where('provider', 'google|apple')->name('monitor.oauth.callback');
        Route::get('/invitations/{token}', [InvitationController::class, 'show'])->name('monitor.invitations.show');
        Route::post('/invitations/{token}', [InvitationController::class, 'store'])->name('monitor.invitations.store');
        Route::get('/forgot-password', [PasswordResetController::class, 'showRequestForm'])->name('monitor.password.request');
        Route::post('/forgot-password', [PasswordResetController::class, 'sendResetLink'])->name('monitor.password.request.store');
        Route::get('/reset-password/{token}', [PasswordResetController::class, 'showResetForm'])->name('monitor.password.reset');
        Route::post('/reset-password/{token}', [PasswordResetController::class, 'resetPassword'])->name('monitor.password.reset.store');
        Route::get('/email-changes/{token}', [EmailChangeController::class, 'show'])->name('monitor.email-changes.show');
        Route::post('/email-changes/{token}', [EmailChangeController::class, 'store'])->name('monitor.email-changes.store');

        Route::middleware(EnsureMonitorAuthenticated::class)->group(function () {
            Route::get('/requests/{requestId}', RequestDetailController::class)->name('monitor.requests.show');
            Route::get('/jobs/attempts/{attemptId}', JobAttemptController::class)->name('monitor.jobs.attempts.show');
            Route::post('/settings/system', [SettingsController::class, 'system'])->name('monitor.settings.system');
            Route::post('/settings/reset', [SettingsController::class, 'reset'])->name('monitor.settings.reset');
            Route::post('/webauthn/register/options', [WebauthnController::class, 'registerOptions'])->name('monitor.webauthn.register.options');
            Route::post('/webauthn/register', [WebauthnController::class, 'register'])->name('monitor.webauthn.register.store');
            Route::get('/{tab?}', DashboardController::class)->name('monitor.dashboard');
        });
    });
