<?php

namespace Visnsstudio\VisnsPackages\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Visnsstudio\VisnsPackages\Services\ReportSemantics\QueryCompiler;
use Visnsstudio\VisnsPackages\Services\ReportSemantics\SemanticModel;

/**
 * Serves the semantic model to the report wizard.
 *
 * This lives outside ReportBuilderController on purpose: that class is
 * already several thousand lines of v1 query building, and the semantic layer
 * has no need of any of it. Execution and export stay on the old controller
 * because they keep their existing routes.
 *
 * The response never contains a table or column name - see
 * {@see SemanticModel::toClientPayload()}.
 */
class SemanticModelController extends \App\Http\Controllers\Controller
{
    /**
     * POST ajax/reportBuilder/semanticModel
     *
     * Response:
     *   {"success": true, "data": {"entities": {...}, "operators": {...}, ...}}
     *
     * An application that has not published a semantic model gets
     * `entities: {}` and a 200 - "not configured" is a legitimate state that
     * the wizard renders as an empty picker, not an error.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function semanticModel(Request $request)
    {
        try {
            $model = app(SemanticModel::class);

            $payload = $model->toClientPayload();

            // Additive metadata: the wizard needs to know which operators it
            // may offer per type, and the compiler is the authority on that.
            $payload['operators'] = SemanticModel::OPERATORS;
            $payload['field_types'] = SemanticModel::TYPES;
            $payload['aggregates'] = QueryCompiler::AGGREGATES;
            $payload['parameter_types'] = QueryCompiler::PARAMETER_TYPES;
            $payload['schema_version'] = 2;

            return response()->json([
                'success' => true,
                'data' => $payload,
            ]);
        } catch (\Throwable $e) {
            // A broken registry is a deployment fault, so it is logged in
            // full and the client only sees a correlation id - the same
            // contract as ReportBuilderController::errorResponse().
            $correlationId = (string) Str::uuid();

            Log::error(
                'Report builder [semanticModel] failure: ' . $e->getMessage(),
                [
                    'correlation_id' => $correlationId,
                    'exception' => get_class($e),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'trace' => $e->getTraceAsString(),
                ]
            );

            $payload = [
                'success' => false,
                'message' => 'The report model could not be loaded',
                'correlation_id' => $correlationId,
            ];

            if (config('app.debug')) {
                $payload['error'] = $e->getMessage();
            }

            return response()->json($payload, 500);
        }
    }
}
