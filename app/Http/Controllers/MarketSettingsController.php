// app/Http/Controllers/MarketSettingsController.php

namespace App\Http\Controllers;

use App\Models\MarketLocation;
use App\Models\MarketLocationType;
use App\Models\MarketSpace;
use App\Models\MarketSpaceType;
use Illuminate\Http\Request;

class MarketSettingsController extends Controller
{
    public function index()
    {
        $locations = MarketLocation::all();
        $locationTypes = MarketLocationType::all();
        $spaces = MarketSpace::all();
        $spaceTypes = MarketSpaceType::all();

        return view('market-settings.index', compact('locations', 'locationTypes', 'spaces', 'spaceTypes'));
    }
    
    // Добавим методы для обработки редактирования и сохранения настроек
    public function update(Request $request)
    {
        // Логика для обновления настроек
        $data = $request->validate([
            'locations' => 'required|array',
            // Другие правила валидации для разных настроек
        ]);

        // Обработка обновления данных

        return redirect()->route('market-settings.index')->with('success', 'Настройки обновлены');
    }
}
