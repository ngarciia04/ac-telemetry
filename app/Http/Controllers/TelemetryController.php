<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\TelemetryUpload;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;

class TelemetryController extends Controller
{
    public function store(Request $request)
    {
        // 1. Aumentar límites para evitar cortes en archivos grandes
        ini_set('max_execution_time', '600');
        ini_set('memory_limit', '512M');

        // 2. Validación
        $request->validate([
            'file' => 'required|file|max:102400', // Subido a 100MB por si acaso
        ]);

        try {
            $file = $request->file('file');
            $originalName = $file->getClientOriginalName();

            // --- LÓGICA ANTI-DUPLICADOS ---
            // Buscamos si ya existe una sesión con el mismo nombre de archivo
            $existing = TelemetryUpload::where('original_filename', $originalName)->first();

            if ($existing) {
                // Borramos el archivo físico del disco para no dejar basura
                if (Storage::disk('public')->exists($existing->stored_path)) {
                    Storage::disk('public')->delete($existing->stored_path);
                }
                // Borramos el registro de la base de datos
                $existing->delete();
            }
            // ------------------------------

            // 3. Guardar el nuevo archivo físico
            $path = $file->store('telemetry', 'public');

            // 4. Crear el nuevo registro en la base de datos
            $upload = TelemetryUpload::create([
                'session_id'        => (string) \Illuminate\Support\Str::uuid(),
                'circuit_name'      => $request->circuit_name ?? 'Desconocido',
                'car_name'          => $request->car_name ?? 'Desconocido',
                'best_lap_time'     => $request->best_lap_time,
                'original_filename' => $originalName,
                'stored_filename'   => basename($path),
                'stored_path'       => $path,
                'file_size'         => $file->getSize(),
                'metadata'          => ['date' => now()]
            ]);

            return response()->json([
                'success' => true,
                'message' => $existing ? 'Sesión actualizada correctamente' : 'Guardado correctamente',
                'data'    => $upload
            ]);

        } catch (\Exception $e) {
            \Log::error('Error en TelemetryController: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'error'   => $e->getMessage()
            ], 500);
        }
    }

    public function show(TelemetryUpload $telemetry)
    {
        // Verificamos si el archivo existe en el disco
        if (!Storage::disk('public')->exists($telemetry->stored_path)) {
            return response()->json(['error' => 'Archivo no encontrado'], 404);
        }

        // Devolvemos el contenido del CSV
        $content = Storage::disk('public')->get($telemetry->stored_path);
        return response($content)->header('Content-Type', 'text/csv');
    }

    public function index()
    {
        // Obtenemos las últimas 10 subidas
        $sessions = TelemetryUpload::orderBy('created_at', 'desc')->take(10)->get();
        
        return response()->json($sessions);
    }

}

