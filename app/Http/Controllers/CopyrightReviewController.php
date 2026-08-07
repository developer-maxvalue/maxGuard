<?php

namespace App\Http\Controllers;

use App\Models\Page;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class CopyrightReviewController extends Controller
{
    /** Persist the human decision made after an external Google search. */
    public function update(Request $request, Page $page): RedirectResponse
    {
        abort_if(
            auth()->id() !== null
            && ! auth()->user()?->is_admin
            && $page->website->user_id !== auth()->id(),
            403
        );
        $data = $request->validate([
            'status' => ['required', 'in:pending,clear,suspected,confirmed'],
            'matched_url' => ['nullable', 'url', 'max:2048'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);
        $page->website->copyrightReviews()->updateOrCreate(
            ['page_id' => $page->id],
            $data + [
                'reviewed_by' => auth()->id(),
                'google_query' => '"'.mb_substr((string) $page->title, 0, 180).'"',
                'reviewed_at' => now(),
            ],
        );

        return back()->with('status', 'Đã lưu kết quả kiểm tra bản quyền thủ công.');
    }
}
