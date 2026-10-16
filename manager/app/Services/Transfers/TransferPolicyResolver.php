<?php

namespace App\Services\Transfers;

use App\Models\Endpoint;
use App\Models\EndpointGroup;
use App\Models\FileTransferPolicy;

/**
 * Política efetiva de transferência de um computador: a do grupo mais próximo na árvore que tenha política
 * (grupo do endpoint, depois cada ancestral) e, por fim, a global. Nenhuma => null => transferências desligadas
 * (negado por padrão). Políticas de outros ramos ou de descendentes nunca valem.
 */
class TransferPolicyResolver
{
    public function forEndpoint(Endpoint $endpoint): ?FileTransferPolicy
    {
        $chain = $this->groupChain($endpoint->endpoint_group_id === null ? null : (int) $endpoint->endpoint_group_id);

        $policies = FileTransferPolicy::query()
            ->whereIn('scope_key', array_merge(array_map(FileTransferPolicy::scopeKeyFor(...), $chain), ['global']))
            ->get()
            ->keyBy('scope_key');

        foreach ($chain as $groupId) {
            if ($policies->has(FileTransferPolicy::scopeKeyFor($groupId))) {
                return $policies->get(FileTransferPolicy::scopeKeyFor($groupId));
            }
        }

        return $policies->get('global');
    }

    /**
     * IDs do grupo e dos ancestrais, do mais próximo ao mais distante (com proteção contra ciclos).
     *
     * @return list<int>
     */
    private function groupChain(?int $groupId): array
    {
        $chain = [];
        while ($groupId !== null && ! in_array($groupId, $chain, true)) {
            $chain[] = $groupId;
            $parent = EndpointGroup::query()->whereKey($groupId)->value('parent_id');
            $groupId = $parent === null ? null : (int) $parent;
        }

        return $chain;
    }
}
