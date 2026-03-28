<?php

namespace Seat\Kassie\Calendar\Http\Controllers;

use Illuminate\Contracts\View\Factory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
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
    private const MOTD_FIELDS = [
        'motd_color_header',
        'motd_color_fleet',
        'motd_color_members',
        'motd_color_pap_value',
        'motd_color_pap_type',
        'motd_color_time',
        'motd_color_error',
        'motd_footer_text',
        'motd_footer_color',
    ];

    private const MOTD_DEFAULTS = [
        'motd_color_header'    => '00ff00',
        'motd_color_fleet'     => 'ffffff',
        'motd_color_members'   => 'ffff00',
        'motd_color_pap_value' => 'ffff00',
        'motd_color_pap_type'  => '00ffff',
        'motd_color_time'      => '00ff00',
        'motd_color_error'     => 'ff0000',
        'motd_footer_text'     => '鱼落星海祝您船蛋平安',
        'motd_footer_color'    => 'ffff00',
    ];

    /**
     * @return Factory|View
     */
    public function index(): Factory|View
    {
        $tags = Tag::all();

        $motd = [];
        foreach (self::MOTD_FIELDS as $field) {
            $motd[$field] = setting('kassie.calendar.' . $field, true) ?: self::MOTD_DEFAULTS[$field];
        }

        $apiToken = setting('kassie.calendar.api_token', true) ?: '';

        return view('calendar::setting.index', [
            'tags' => $tags,
            'motd' => $motd,
            'apiToken' => $apiToken,
        ]);
    }

    public function updateMotd(Request $request): RedirectResponse
    {
        $colorFields = [
            'motd_color_header', 'motd_color_fleet', 'motd_color_members',
            'motd_color_pap_value', 'motd_color_pap_type', 'motd_color_time',
            'motd_color_error', 'motd_footer_color',
        ];

        foreach ($colorFields as $field) {
            $value = ltrim($request->input($field, ''), '#');
            if (preg_match('/^[0-9a-fA-F]{6}$/', $value)) {
                setting(['kassie.calendar.' . $field, strtolower($value)], true);
            }
        }

        $footerText = $request->input('motd_footer_text', '');
        setting(['kassie.calendar.motd_footer_text', mb_substr($footerText, 0, 100)], true);

        return redirect()->back()->with('success', trans('calendar::seat.motd_saved'));
    }

    public function regenerateApiToken(): RedirectResponse
    {
        $token = Str::random(48);
        setting(['kassie.calendar.api_token', $token], true);

        return redirect()->back()->with('success', trans('calendar::seat.api_token_regenerated'));
    }

    public function deleteApiToken(): RedirectResponse
    {
        setting(['kassie.calendar.api_token', ''], true);

        return redirect()->back()->with('success', trans('calendar::seat.api_token_deleted'));
    }
}
