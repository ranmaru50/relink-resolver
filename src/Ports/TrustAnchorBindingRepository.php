<?php
// src/Ports/TrustAnchorBindingRepository.php
// Trust Anchor Binding (§9.3) の永続化ポート。Binding は policy/admin 経路でのみ格納され、
// 公開エンドポイントは決して書き込まない（§6.4/§16）。読み出し時の keyId 再導出（§9.4）は
// アダプタが TrustAnchorBinding::fromPolicyChannel 経由で保証する。

declare(strict_types=1);

namespace Relink\Resolver\Ports;

use Relink\Resolver\Domain\L2\TrustAnchorBinding;

interface TrustAnchorBindingRepository
{
    /** Store a Trust Anchor Binding delivered through the policy channel (§9.3). */
    public function storeBinding(TrustAnchorBinding $binding): void;

    /** @return ?TrustAnchorBinding the binding for the anchor, keyId re-derived per §9.4, null when absent */
    public function findBinding(string $anchorId): ?TrustAnchorBinding;
}
