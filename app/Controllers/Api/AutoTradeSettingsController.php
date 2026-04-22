<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Request;
use App\Core\Response;
use App\Services\Automation\AutoTradeSettingsService;
use InvalidArgumentException;
use Throwable;

final class AutoTradeSettingsController
{
    public function __construct(private readonly AutoTradeSettingsService $settings)
    {
    }

    public function show(Request $request): Response
    {
        return Response::json([
            'status' => 'ok',
            'settings' => $this->settings->show(),
        ]);
    }

    public function update(Request $request): Response
    {
        try {
            return Response::json([
                'status' => 'ok',
                'message' => 'Auto trade settings saved.',
                'settings' => $this->settings->update($request->body),
            ]);
        } catch (InvalidArgumentException $exception) {
            return Response::json([
                'status' => 'error',
                'message' => $exception->getMessage(),
            ], 422);
        } catch (Throwable $throwable) {
            return Response::json([
                'status' => 'error',
                'message' => $throwable->getMessage(),
            ], 500);
        }
    }
}
