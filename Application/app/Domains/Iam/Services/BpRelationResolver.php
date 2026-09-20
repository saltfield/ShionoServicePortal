<?php

namespace App\Domains\Iam\Services;

use Illuminate\Support\Facades\DB;

class BpRelationResolver
{
    /**
     * @return array{relation: string, depth_diff: int|null, depth_diff_to_applicant: int|null}
     */
    public function resolve(?int $actorBpId, ?int $targetBpId): array
    {
        if ($actorBpId === null || $targetBpId === null) {
            return [
                'relation' => 'unrelated',
                'depth_diff' => null,
                'depth_diff_to_applicant' => null,
            ];
        }

        if ($actorBpId === $targetBpId) {
            return [
                'relation' => 'same_bp',
                'depth_diff' => 0,
                'depth_diff_to_applicant' => 0,
            ];
        }

        $asAncestor = DB::table('bp_closure')
            ->where('ancestor_id', $actorBpId)
            ->where('descendant_id', $targetBpId)
            ->where('depth_diff', '>', 0)
            ->first();

        if ($asAncestor) {
            return [
                'relation' => 'descendant',
                'depth_diff' => (int) $asAncestor->depth_diff,
                'depth_diff_to_applicant' => (int) $asAncestor->depth_diff,
            ];
        }

        $asDescendant = DB::table('bp_closure')
            ->where('ancestor_id', $targetBpId)
            ->where('descendant_id', $actorBpId)
            ->where('depth_diff', '>', 0)
            ->first();

        if ($asDescendant) {
            return [
                'relation' => 'ancestor',
                'depth_diff' => (int) $asDescendant->depth_diff,
                'depth_diff_to_applicant' => (int) $asDescendant->depth_diff,
            ];
        }

        return [
            'relation' => 'unrelated',
            'depth_diff' => null,
            'depth_diff_to_applicant' => null,
        ];
    }
}
