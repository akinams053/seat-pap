<?php

namespace Seat\Kassie\Calendar\Http\Controllers;

use Illuminate\Contracts\View\Factory;
use Illuminate\View\View;
use Seat\Kassie\Calendar\Models\Tag;
use Seat\Web\Http\Controllers\Controller;

/**
 * Class SettingController.
 *
 * @package Seat\Kassie\Calendar\Http\Controllers
 */
class SettingController extends Controller
{
    /**
     * @return Factory|View
     */
    public function index(): Factory|View
    {
        $tags = Tag::all();

        return view('calendar::setting.index', [
            'tags' => $tags,
        ]);
    }
}
