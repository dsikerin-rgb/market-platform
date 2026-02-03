<?php

namespace App\Http\Controllers\Cabinet;

use App\Http\Controllers\Controller;
use Illuminate\View\View;

class CustomerChatController extends Controller
{
    public function __invoke(): View
    {
        return view('cabinet.customer-chat');
    }
}
