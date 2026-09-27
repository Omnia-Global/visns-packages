<?php

namespace Visnsstudio\VisnsPackages\Tests\Platform\Security;

use App\Models\SecOwner;
use App\Models\SecProbe;
use App\Models\SecWidget;
use PHPUnit\Framework\Attributes\Test;
use Visnsstudio\VisnsPackages\Support\RelationGuard;
use Visnsstudio\VisnsPackages\Tests\TestCase;

/** 4.17.2: RelationGuard decides by reflection and allowlists, never by calling. */
class RelationGuardTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        RelationGuard::flush();
        SecProbe::$calls = 0;
    }

    #[Test]
    public function relation_methods_are_recognised_by_return_type_or_body(): void
    {
        $probe = new SecProbe();

        $this->assertTrue(RelationGuard::isRelation($probe, 'typed'));
        $this->assertTrue(RelationGuard::isRelation($probe, 'nullableTyped'));
        $this->assertTrue(RelationGuard::isRelation($probe, 'untyped'));
        $this->assertTrue(RelationGuard::isRelation(SecProbe::class, 'typed'), 'a class name works too');
        $this->assertSame(0, SecProbe::$calls, 'deciding never calls the method');
    }

    #[Test]
    public function the_models_own_allowlists_are_honoured(): void
    {
        $probe = new SecProbe();

        $this->assertTrue(RelationGuard::isRelation($probe, 'listedByProperty'));
        $this->assertTrue(RelationGuard::isRelation($probe, 'listedByLoadable'), '`name:columns` is read as the name');
        $this->assertSame(0, SecProbe::$calls);
    }

    #[Test]
    public function other_methods_are_not_relations(): void
    {
        $probe = new SecProbe();

        foreach ([
            'sideEffect',      // no relation builder
            'commentedOut',    // the builders are only in comments
            'wrongType',       // declared non-Relation return type
            'needsArgument',   // a required parameter
            'staticOne',       // static
            'hidden',          // not public
            'delete', 'save', 'forceDelete', 'replicate', 'push', 'touch', 'refresh', 'newQuery', // framework
            'restore', 'trashed', // SoftDeletes trait
            'missing',         // no such method
            '', 'a b', 'owner()', '../x', 'typed.owner', // not a name
        ] as $name) {
            $this->assertFalse(RelationGuard::isRelation($probe, $name), $name);
        }
        $this->assertFalse(RelationGuard::isRelation($probe, null));
        $this->assertFalse(RelationGuard::isRelation($probe, ['typed']));

        $this->assertFalse(RelationGuard::isRelation(new SecWidget(), 'purge'));
        $this->assertFalse(RelationGuard::isRelation(new SecWidget(), 'promoteOwner'));
        $this->assertSame(0, SecProbe::$calls);
        $this->assertSame(0, SecWidget::$purged);
    }

    #[Test]
    public function a_dotted_path_is_checked_segment_by_segment_through_the_related_models(): void
    {
        $widget = new SecWidget();

        $this->assertTrue(RelationGuard::isRelationPath($widget, 'owner'));
        $this->assertTrue(RelationGuard::isRelationPath($widget, 'owner.widgets'));
        $this->assertTrue(RelationGuard::isRelationPath($widget, 'owner.widgets.parts'));
        $this->assertInstanceOf(SecOwner::class, RelationGuard::relatedModelForPath($widget, 'owner'));

        $this->assertFalse(RelationGuard::isRelationPath($widget, 'owner.widgets.purge'));
        $this->assertFalse(RelationGuard::isRelationPath($widget, 'owner.nope'));
        $this->assertFalse(RelationGuard::isRelationPath($widget, 'purge'));
        $this->assertFalse(RelationGuard::isRelationPath($widget, ''));
        $this->assertFalse(RelationGuard::isRelationPath($widget, 'owner..widgets'));
        $this->assertSame(0, SecWidget::$purged);
    }

    #[Test]
    public function a_relation_registered_with_resolve_relation_using_counts(): void
    {
        SecProbe::resolveRelationUsing('dynamicParts', fn ($model) => $model->hasMany(SecOwner::class));

        $this->assertTrue(RelationGuard::isRelation(new SecProbe(), 'dynamicParts'));
    }
}
