<?php namespace App\Repositories\BugRepository;

use App\Repositories\BugRepository\BugRepositoryInterface;
use DB;
use App\Models\Bug;

class DBBugRepository implements BugRepositoryInterface
{
    protected $model;

    public function __construct()
    {
        $this->model = new \App\Models\Bug();
    }

    public function getById($id)
    {
        return Bug::where('id', '=', $id)->first();
    }

    public function getAll($count)
    {
        return DB::table('bug_reports')->take($count)->get();
    }

    public function getNewest($count)
    {
        return DB::table('bug_reports')
            ->whereNull('deleted_at')
            ->orderBy('created_at', 'desc')
            ->take($count)
            ->get();
    }

    public function getSortedByVotes($table, $order, $count)
    {
        return DB::table('bug_reports')
            ->whereNull('deleted_at')
            ->orderBy($table, $order)
            ->take($count)
            ->get();
    }

    public function getByStatus($status, $count)
    {
        return DB::table('bug_reports')
            ->whereNull('deleted_at')
            ->where('status', '=', $status)
            ->take($count)
            ->get();
    }
}