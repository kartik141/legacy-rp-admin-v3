<?php

namespace App\Http\Controllers;

use App\Http\Resources\LogResource;
use App\Log;
use App\Player;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class LogController extends Controller
{

    /**
     * Display a listing of the resource.
     *
     * @param Request $request
     * @return Response
     */
    public function index(Request $request): Response
    {
        $start = round(microtime(true) * 1000);

        $query = Log::query()->orderByDesc('timestamp');

        // Filtering by identifier.
        if ($identifier = $this->multiValues($request->input('identifier'))) {
            /**
             * @var $q Builder
             */
            $query->where(function ($q) use ($identifier) {
                foreach ($identifier as $i) {
                    $q->orWhere('identifier', $i);
                }
            });
        }

        // Filtering by before.
        if ($before = $request->input('before')) {
            $query->where(DB::raw('UNIX_TIMESTAMP(`timestamp`)'), '<', $before);
        }

        // Filtering by after.
        if ($after = $request->input('after')) {
            $query->where(DB::raw('UNIX_TIMESTAMP(`timestamp`)'), '>', $after);
        }

        // Filtering by server.
        if ($server = $this->multiValues($request->input('server'))) {
            /**
             * @var $q Builder
             */
            $query->where(function ($q) use ($server) {
                foreach ($server as $s) {
                    $q->orWhere('details', 'LIKE', '% [' . intval($s) . '] %');
                }
            });
        }

        // Filtering by action.
        if ($action = $this->multiValues($request->input('action'))) {
            /**
             * @var $q Builder
             */
            $query->where(function ($q) use ($action) {
                foreach ($action as $a) {
                    if (Str::startsWith($a, '=')) {
                        $a = Str::substr($a, 1);
                        $q->orWhere('action', $a);
                    } else {
                        $q->orWhere('action', 'like', "%{$a}%");
                    }
                }
            });
        }

        // Filtering by details.
        if ($details = $request->input('details')) {
            if (Str::startsWith($details, '=')) {
                $details = Str::substr($details, 1);
                $query->where('details', $details);
            } else {
                $query->where('details', 'like', "%{$details}%");
            }
        }

        $page = Paginator::resolveCurrentPage('page');

        $query->select(['id', 'identifier', 'action', 'details', 'metadata', 'timestamp']);
        $query->limit(15)->offset(($page - 1) * 15);

        $logs = LogResource::collection($query->get());

        $end = round(microtime(true) * 1000);

        return Inertia::render('Logs/Index', [
            'logs'      => $logs,
            'filters'   => $request->all(
                'identifier',
                'server',
                'action',
                'details',
                'after',
                'before'
            ),
            'links'     => $this->getPageUrls($page),
            'time'      => $end - $start,
            'playerMap' => Player::fetchSteamPlayerNameMap($logs->toArray($request), 'steamIdentifier'),
            'page'      => $page,
        ]);
    }

    private function multiValues(?string $val): ?array
    {
        if (!$val) {
            return null;
        }

        return array_values(array_map(function ($v) {
            return trim($v);
        }, explode(',', $val)));
    }

}
