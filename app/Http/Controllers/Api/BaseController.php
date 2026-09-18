<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BaseController extends Controller
{
    protected $model;

    protected $searchable = [];

    protected $filterable = [];

    protected $sortable = ['created_at'];

    protected $defaultSort = 'created_at';

    protected $defaultSortDir = 'desc';

    protected $perPage = 50;

    public function index(Request $request): JsonResponse
    {
        $query = $this->model->newQuery();
        $this->applyFilters($query, $request);
        $this->applySearch($query, $request);
        $this->applySorting($query, $request);

        $total = $query->count();
        $data = $query->offset(($request->page ?? 1 - 1) * $this->perPage)
            ->limit($this->perPage)
            ->get();

        return response()->json([
            'data' => $data,
            'total' => $total,
            'current_page' => $request->input('page', 1),
            'per_page' => $this->perPage,
            'last_page' => (int) ceil($total / $this->perPage),
        ]);
    }

    protected function applyFilters(Builder $query, Request $request): void
    {
        foreach ($this->filterable as $field) {
            $value = $request->input($field);
            if ($value !== null && $value !== '') {
                $query->where($field, $value);
            }
        }
    }

    protected function applySearch(Builder $query, Request $request): void
    {
        $q = $request->input('search', '');
        if (empty($q)) {
            return;
        }

        $query->where(function (Builder $builder) use ($q) {
            foreach ($this->searchable as $field) {
                $builder->orWhere($field, 'like', "%{$q}%");
            }
        });
    }

    protected function applySorting(Builder $query, Request $request): void
    {
        $sortField = $request->input('sort', $this->defaultSort);
        $sortDir = $request->input('direction', 'asc');

        if (! in_array($sortField, $this->sortable)) {
            $sortField = $this->defaultSort;
        }

        if (! in_array(strtolower($sortDir), ['asc', 'desc'])) {
            $sortDir = 'asc';
        }

        $query->orderBy($sortField, $sortDir);
    }

    protected function paginate(Builder $query, Request $request): JsonResponse
    {
        $page = $request->input('page', 1);
        $perPage = $request->input('per_page', $this->perPage);
        $total = $query->count();

        $data = $query->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->get();

        return response()->json([
            'data' => $data,
            'total' => $total,
            'current_page' => $page,
            'per_page' => $perPage,
            'last_page' => (int) ceil($total / $perPage),
        ]);
    }
}
