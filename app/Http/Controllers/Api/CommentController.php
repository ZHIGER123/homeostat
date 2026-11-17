<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Comment;
use App\Services\CommentService;
use Illuminate\Http\Response;

class CommentController extends Controller
{
    public function __construct(private readonly CommentService $comments) {}

    /** DELETE /api/v1/comments/{comment} — автор или администратор (проверка прав — ПР8). */
    public function destroy(Comment $comment): Response
    {
        $this->comments->delete($comment);

        return response()->noContent();
    }
}
