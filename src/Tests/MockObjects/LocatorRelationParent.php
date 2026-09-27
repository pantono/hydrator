<?php

namespace Pantono\Hydrator\Tests\MockObjects;

use Pantono\Contracts\Attributes\EagerLoad;
use Pantono\Contracts\Attributes\Lazy;
use Pantono\Contracts\Attributes\Locator;
use Pantono\Contracts\Attributes\Database\OneToOne;

#[EagerLoad]
class LocatorRelationParent
{
    #[OneToOne(targetModel: LocatedRelation::class)]
    private ?LocatedRelation $located = null;

    public function getLocated(): ?LocatedRelation
    {
        return $this->located;
    }

    public function setLocated(?LocatedRelation $located): void
    {
        $this->located = $located;
    }

    #[Locator(serviceName: 'Custom', methodName: 'find')]
    private ?LocatedRelation $immediate = null;

    public function getImmediate(): ?LocatedRelation
    {
        return $this->immediate;
    }

    public function setImmediate(?LocatedRelation $immediate): void
    {
        $this->immediate = $immediate;
    }

    #[Locator(serviceName: 'Custom', methodName: 'find'), Lazy]
    private ?LocatedRelation $deferred = null;

    public function getDeferred(): ?LocatedRelation
    {
        return $this->deferred;
    }

    public function setDeferred(?LocatedRelation $deferred): void
    {
        $this->deferred = $deferred;
    }

}
