<?php

namespace App\Http\Controllers;

use App\Models\Family;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Gd\Driver;

class FamilyController extends Controller
{
    public function index(Request $request)
    {
        $items = $request->user()->families;

        return response()->json(['success' => 'ok', 'items' => $items]);
    }


    public function members(Request $request, $id)
    {
        $user = $request->user();
        $family = Family::with('users')->findOrFail($id);

        // verifico che l'utente appartenga effettivamente alla famiglia
        if (!$user->families->contains('id', $id)) {
            return response()->json(['success' => 'ko']);
        }

        return response()->json(['success' => 'ok', 'members' => $family->users]);
    }


    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|min:3|max:50'
        ]);

        $user = $request->user()->load('families');

        $family = new Family();
        $family->name = $request->name;
        $family->slug = Str::slug($family->name);
        $family->code = Str::substr($family->id . Str::random(5), 5); // il codice è sempre l'id + caratteri random per un massimo di 5 caratteri 
        $family->save();

        $current = $user->families->count() ? 0 : 1;

        $user->families()->attach($family->id, ['current' => $current]);

        if (!$user->has_completed_wizard) {
            $user->has_completed_wizard = 1;
        }

        return response()->json(['success' => 'ok', 'family' => $family, 'user' => $user]);
    }


    public function update(Request $request, $id)
    {
        $request->validate([
            'name' => 'required|min:3|max:50'
        ]);

        $family = Family::findOrFail($id);
        $family->name = $request->name;
        $family->slug = Str::slug($family->name);
        $family->save();

        return response()->json(['success' => 'ok', 'family' => $family]);
    }


    public function upload(Request $request, $id)
    {
        $family = Family::findOrFail($id);

        // ✅ opzionale: autorizzazione
        // $this->authorize('update', $family);

        // ✅ validazione
        $request->validate([
            'photo' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'], // 5MB
        ]);

        $file = $request->file('photo');

        $manager = new ImageManager(new Driver());
        $image = $manager->read($file->getPathname());

        // ✅ ridimensiona mantenendo proporzioni
        $image->scaleDown(1600);

        // ✅ encoding (webp se vuoi)
        $encoded = $image->toJpeg(85);

        // ✅ (A) nome file deterministico (sovrascrive sempre)
        // $filename = 'profile.' . $file->getClientOriginalExtension();
        // $path = $file->storeAs("families/{$family->id}", $filename, 'public');

        // ✅ (B) nome file unico (non sovrascrive, ma devi cancellare il vecchio)
        $filename = Str::uuid()->toString() . '.' . $file->getClientOriginalExtension();
        $path = "families/{$family->id}/{$filename}";

        // ✅ cancella eventuale immagine precedente (se presente)
        if ($family->profile_photo_path) {
            Storage::disk('public')->delete($family->profile_photo_path);
        }

        // TODO: creare anche altre versioni (thumb...)

        Storage::disk('public')->put($path, $encoded);

        // salva path nel DB
        $family->profile_photo_path = $path;
        $family->save();

        return response()->json([
            'success' => 'ok',
            'family' => $family,
        ]);
    }

    public function code(Request $request, $id)
    {
        // TODO
        return response()->json(['success' => 'ok']);
    }


    public function join(Request $request)
    {
        $user = $request->user();

        // codice mancante
        if ($request->missing('code')) {
            return response()->json(['success' => 'ko', 'nessage' => 'Codice mancante nella richiesta'], 422);
        }

        $family = Family::where('code', $request->code)->firstOrFail();

        // TODO 
        // famiglia già associata (non dovrebbe mai verificarsi) 409
        //if($user->families)

        $user->families()->attach($family->id, ['current' => 1]);

        // se è la prima famiglia, imposto che l'utente è a posto col wizard
        if (!$user->has_completed_wizard) {
            $user->has_completed_wizard = 1;
            $user->save();
        }

        return response()->json(['success' => 'ok', 'family' => $family]);
    }


    public function leave(Request $request, $family_id, $user_id)
    {
        $user = $request->user();

        // l'ugtente non può rimuovere sè stesso dalla famiglia
        if ($user->id === $user_id) {
            return response()->json(['success' => 'ko', 'nessage' => 'Non puoi rimuovere te stesso!'], 409);
        }

        // TODO rimozione vera e propria

        return response()->json(['success' => 'ok']);
    }
}
