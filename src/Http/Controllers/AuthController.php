<?php

declare(strict_types=1);

namespace RoyalSpin\Http\Controllers;

use RoyalSpin\Auth\AuthService;
use RoyalSpin\Http\Request;
use RoyalSpin\Http\Response;
use RoyalSpin\Support\Session;
use RoyalSpin\Support\Validator;

final class AuthController
{
    public function showLogin(Request $request): never
    {
        if (Session::userId() !== null) {
            Response::redirect('/dashboard');
        }
        Response::view('auth/login', [
            'title' => 'Sign in',
            'error' => Session::flash('error'),
            'notice'=> Session::flash('notice'),
        ]);
    }

    public function showRegister(Request $request): never
    {
        if (Session::userId() !== null) {
            Response::redirect('/dashboard');
        }
        Response::view('auth/register', ['title' => 'Create account', 'error' => Session::flash('error')]);
    }

    public function login(Request $request): never
    {
        $identifier = trim($request->string('identifier'));
        $password   = $request->string('password');

        if ($identifier === '' || $password === '') {
            $this->fail($request, 'Please enter your username and password.');
        }

        $result = (new AuthService())->login($identifier, $password, $request->ip());
        if (!($result['ok'] ?? false)) {
            $this->fail($request, (string) ($result['error'] ?? 'Sign in failed.'));
        }

        Session::login((int) $result['user_id']);

        if ($request->wantsJson) {
            Response::json(['ok' => true, 'redirect' => url('/dashboard')]);
        }
        Response::redirect('/dashboard');
    }

    public function register(Request $request): never
    {
        $validator = new Validator($request->all());
        $username  = $validator->username();
        $email     = $validator->email();
        $password  = $validator->password();

        $confirm = $request->string('password_confirmation');
        if ($password !== null && $confirm !== '' && $password !== $confirm) {
            $validator->addError('password_confirmation', 'The passwords do not match.');
        }

        if ($validator->fails() || $username === null || $email === null || $password === null) {
            $this->fail($request, (string) $validator->firstError());
        }

        $result = (new AuthService())->register($username, $email, $password, $request->ip());
        if (!($result['ok'] ?? false)) {
            $this->fail($request, (string) ($result['error'] ?? 'Could not create the account.'));
        }

        Session::login((int) $result['user_id']);

        if ($request->wantsJson) {
            Response::json(['ok' => true, 'redirect' => url('/dashboard')]);
        }
        Response::redirect('/dashboard');
    }

    public function logout(Request $request): never
    {
        Session::logout();
        if ($request->wantsJson) {
            Response::json(['ok' => true, 'redirect' => url('/login')]);
        }
        Response::redirect('/login');
    }

    private function fail(Request $request, string $message): never
    {
        if ($request->wantsJson) {
            Response::error($message, 422);
        }
        Session::flash('error', $message);
        Response::redirect($request->path);
    }
}
