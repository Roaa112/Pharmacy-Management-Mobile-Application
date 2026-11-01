<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Foundation\Auth\AuthenticatesUsers;

class LoginController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | Login Controller
    |--------------------------------------------------------------------------
    |
    | This controller handles authenticating users for the application and
    | redirecting them to your home screen. The controller uses a trait
    | to conveniently provide its functionality to your applications.
    |
    */

    use AuthenticatesUsers;

    /**
     * Where to redirect users after login.
     *
     * @var string
     */
    protected $redirectTo = '/dashboard';

    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct()
    {
        // This login is for admins, so both checks must use the "admin" guard.
        $this->middleware('guest:admin')->except('logout');
        $this->middleware('auth:admin')->only('logout');
    }
protected function guard()
{
    return Auth::guard('admin');
}
protected function redirectTo()
{
    return '/dashboard';
}


    protected function attemptLogin(Request $request)
    {

        return Auth::guard('admin')->attempt(
            // Deactivated admins cannot sign in.
            $this->credentials($request) + ['is_active' => true], $request->boolean('remember')
        );
    }
}
