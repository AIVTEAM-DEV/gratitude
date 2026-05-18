<?php

namespace App\Http\Controllers\Gratitude;

use App\Http\Controllers\Controller;
use App\Models\Gratitude\GratitudeLevel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class GratitudeLevelController extends Controller
{
    public function index()
    {
        $levels = GratitudeLevel::orderBy('min_points')->get();

        return response()->json($levels);
    }

    public function media(string $path)
    {
        $path = ltrim(preg_replace('#^/?storage/#', '', $path), '/');

        if ($path === '' || str_contains($path, '..') || ! Storage::disk('public')->exists($path)) {
            abort(404);
        }

        return Storage::disk('public')->response($path);
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'min_points' => 'required|numeric|min:0',
            'max_points' => 'nullable|numeric|gte:min_points',
            'redemption_points_per_dollar' => 'nullable|numeric|min:1',
            'partner_points_per_dollar' => 'nullable|numeric|min:1',
            'earned_expire_days' => 'nullable|integer|min:1',
            'bonus_expire_days' => 'nullable|integer|min:1',
            'level_interval_years' => 'nullable|integer|min:1',
            'min_journeys' => 'nullable|integer|min:0',
            'level_rules' => 'nullable|string', // JSON string from FormData
            'level_image' => 'nullable|file|mimes:jpg,jpeg,png,webp,gif,svg|max:4096',
            'level_icon' => 'nullable|file|mimes:jpg,jpeg,png,webp,gif,svg|max:4096',
        ]);

        $data = $request->only('name', 'min_points', 'max_points', 'earned_expire_days', 'bonus_expire_days', 'level_interval_years', 'min_journeys');
        $data['status'] = filter_var($request->input('status', true), FILTER_VALIDATE_BOOLEAN);
        $data['redemption_points_per_dollar'] = $request->input('redemption_points_per_dollar', 35);
        $data['partner_points_per_dollar'] = $request->input('partner_points_per_dollar', $data['redemption_points_per_dollar']);
        $data['earned_expire_days'] = (int) $request->input('earned_expire_days', 730);
        $data['bonus_expire_days'] = (int) $request->input('bonus_expire_days', 730);
        $data['level_interval_years'] = (int) $request->input('level_interval_years', 2);
        $data['min_journeys'] = (int) $request->input('min_journeys', 0);

        if ($request->filled('level_rules')) {
            $data['level_rules'] = json_decode($request->input('level_rules'), true);
        }

        if ($request->hasFile('level_image')) {
            $data['level_image'] = $request->file('level_image')->store('gratitude-levels', 'public');
        }

        if ($request->hasFile('level_icon')) {
            $data['level_icon'] = $request->file('level_icon')->store('gratitude-levels', 'public');
        }

        $level = GratitudeLevel::create($data);

        return response()->json(['message' => 'Gratitude Level created successfully.', 'level' => $level], 201);
    }

    public function update(Request $request, GratitudeLevel $level)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'min_points' => 'required|numeric|min:0',
            'max_points' => 'nullable|numeric|gte:min_points',
            'redemption_points_per_dollar' => 'nullable|numeric|min:1',
            'partner_points_per_dollar' => 'nullable|numeric|min:1',
            'earned_expire_days' => 'nullable|integer|min:1',
            'bonus_expire_days' => 'nullable|integer|min:1',
            'level_interval_years' => 'nullable|integer|min:1',
            'min_journeys' => 'nullable|integer|min:0',
            'level_rules' => 'nullable|string',
            'level_image' => 'nullable|file|mimes:jpg,jpeg,png,webp,gif,svg|max:4096',
            'level_icon' => 'nullable|file|mimes:jpg,jpeg,png,webp,gif,svg|max:4096',
        ]);

        $data = $request->only('name', 'min_points', 'max_points', 'earned_expire_days', 'bonus_expire_days', 'level_interval_years', 'min_journeys');
        // Handle vue sending boolean as string "true" / "false" in FormData
        $data['status'] = filter_var($request->input('status', true), FILTER_VALIDATE_BOOLEAN);
        if ($request->filled('redemption_points_per_dollar')) {
            $data['redemption_points_per_dollar'] = $request->input('redemption_points_per_dollar');
        }
        if ($request->filled('partner_points_per_dollar')) {
            $data['partner_points_per_dollar'] = $request->input('partner_points_per_dollar');
        }
        $data['earned_expire_days'] = (int) $request->input('earned_expire_days', $level->earned_expire_days ?: 730);
        $data['bonus_expire_days'] = (int) $request->input('bonus_expire_days', $level->bonus_expire_days ?: 730);
        $data['level_interval_years'] = (int) $request->input('level_interval_years', $level->level_interval_years ?: 2);
        $data['min_journeys'] = (int) $request->input('min_journeys', $level->min_journeys ?: 0);

        if ($request->has('level_rules')) {
            $rawRules = $request->input('level_rules');
            $data['level_rules'] = ($rawRules !== '' && $rawRules !== null)
                ? json_decode($rawRules, true)
                : null;
        }

        if ($request->hasFile('level_image')) {
            if ($level->level_image) {
                Storage::disk('public')->delete($level->level_image);
            }
            $data['level_image'] = $request->file('level_image')->store('gratitude-levels', 'public');
        }

        if ($request->hasFile('level_icon')) {
            if ($level->level_icon) {
                Storage::disk('public')->delete($level->level_icon);
            }
            $data['level_icon'] = $request->file('level_icon')->store('gratitude-levels', 'public');
        }

        $level->update($data);

        return response()->json(['message' => 'Gratitude Level updated successfully.', 'level' => $level]);
    }

    public function destroy(GratitudeLevel $level)
    {
        if ($level->level_image) {
            Storage::disk('public')->delete($level->level_image);
        }
        if ($level->level_icon) {
            Storage::disk('public')->delete($level->level_icon);
        }

        $level->delete();

        return response()->json(['message' => 'Gratitude Level deleted successfully.']);
    }
}
