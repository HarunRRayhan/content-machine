<?php

namespace Tests\Feature\Media;

use App\Models\Post;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Media\PostDesignTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PostDesignTemplatesControllerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: User, 1: Workspace}
     */
    private function actingAsWorkspaceMember(): array
    {
        $workspace = Workspace::factory()->create();
        $team = $workspace->team;
        $user = User::factory()->create(['current_team_id' => $team->id]);
        $team->members()->attach($user->id, ['role' => 'owner']);

        $this->actingAs($user);

        return [$user, $workspace];
    }

    public function test_templates_index_lists_only_active_catalog_entries(): void
    {
        $this->actingAsWorkspaceMember();

        $this->get(route('media.templates'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('media/templates/index')
                ->has('templates', 7)
                ->where('templates.0.letter', 'A')
                ->where('templates.5.letter', 'G')
                ->where('templates.6.letter', 'H')
                ->where('templates.0.preview_url', asset('images/templates/template-a-light-data-driven.png'))
                ->where('templates.5.slug', 'template-g-handwritten-explainer')
                ->where('templates.5.directory', 'template-g-handwritten-explainer')
                ->where('templates.5.name', 'Handwritten Blue Explainer')
                ->where('templates.6.slug', 'template-h-dark-systems-explainer')
                ->where('templates.6.preview_url', asset('images/templates/template-h-dark-systems-explainer.png'))
            );
    }

    public function test_template_show_lists_posts_tagged_with_that_letter(): void
    {
        [, $workspace] = $this->actingAsWorkspaceMember();

        Post::factory()->create([
            'workspace_id' => $workspace->id,
            'human_id' => 'P-63',
            'number' => 63,
            'title' => 'Database pairs',
            'template' => 'D',
            'status' => 'draft',
        ]);
        Post::factory()->create([
            'workspace_id' => $workspace->id,
            'human_id' => 'P-64',
            'number' => 64,
            'title' => 'HTTP codes',
            'template' => 'E',
            'status' => 'draft',
        ]);

        $this->get(route('media.templates.show', ['letter' => 'D']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('media/templates/show')
                ->where('template.letter', 'D')
                ->where('template.preview_url', asset('images/templates/template-d-split-comparison.png'))
                ->has('posts', 1)
                ->where('posts.0.human_id', 'P-63')
            );
    }

    public function test_unknown_template_letter_404s(): void
    {
        $this->actingAsWorkspaceMember();

        $this->get(route('media.templates.show', ['letter' => 'Z']))->assertNotFound();
    }

    public function test_archived_template_metadata_remains_available_for_historical_posts(): void
    {
        [, $workspace] = $this->actingAsWorkspaceMember();

        Post::factory()->create([
            'workspace_id' => $workspace->id,
            'human_id' => 'P-84',
            'number' => 84,
            'title' => 'Output gate',
            'template' => 'J',
            'status' => 'draft',
        ]);

        $this->get(route('media.templates.show', ['letter' => 'J']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('template.letter', 'J')
                ->where('template.name', 'Trajectory Output Gate')
                ->where('template.status', 'archived')
                ->where('template.directory', 'archived/template-j-trajectory-output-gate')
                ->where('template.successor', 'H')
                ->has('posts', 1)
                ->where('posts.0.human_id', 'P-84')
            );
    }

    public function test_post_design_template_catalog_covers_every_letter(): void
    {
        $this->assertSame(['A', 'B', 'C', 'D', 'F', 'G', 'H'], PostDesignTemplate::activeLetters());
        $this->assertCount(7, PostDesignTemplate::all());

        foreach (PostDesignTemplate::letters() as $letter) {
            $template = PostDesignTemplate::from($letter);

            $this->assertFileExists(public_path("images/templates/{$template->slug}.png"));
            $this->assertSame(
                asset("images/templates/{$template->slug}.png"),
                $template->toArray()['preview_url'],
                "Template {$letter} should resolve its preview asset.",
            );
        }

        $this->assertSame('D', PostDesignTemplate::from('d')->letter);
        $this->assertSame('archived/template-i-linux-security-explainer', PostDesignTemplate::from('I')->directory);
        $this->assertSame('archived', PostDesignTemplate::from('I')->status);
        $this->assertSame('H', PostDesignTemplate::from('I')->successor);
        $this->assertSame('archived/template-j-trajectory-output-gate', PostDesignTemplate::from('J')->directory);
        $this->assertSame('archived', PostDesignTemplate::from('J')->status);
        $this->assertSame('H', PostDesignTemplate::from('J')->successor);
        $this->assertSame('archived/template-e-cheatsheet-doodle', PostDesignTemplate::from('E')->directory);
        $this->assertSame('G', PostDesignTemplate::from('E')->successor);
        $this->assertNull(PostDesignTemplate::tryFrom(null));
    }
}
