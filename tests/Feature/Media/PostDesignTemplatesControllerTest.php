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

    public function test_templates_index_lists_a_through_f(): void
    {
        $this->actingAsWorkspaceMember();

        $this->get(route('media.templates'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('media/templates/index')
                ->has('templates', 6)
                ->where('templates.0.letter', 'A')
                ->where('templates.5.letter', 'F')
                ->where('templates.0.preview_url', asset('images/templates/template-a-light-data-driven.png'))
                ->where('templates.5.preview_url', asset('images/templates/template-f-product-showcase.png'))
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

    public function test_post_design_template_catalog_covers_every_letter(): void
    {
        $this->assertCount(6, PostDesignTemplate::all());

        foreach (PostDesignTemplate::all() as $template) {
            $this->assertFileExists(public_path("images/templates/{$template->slug}.png"));
        }

        $this->assertSame('D', PostDesignTemplate::from('d')->letter);
        $this->assertNull(PostDesignTemplate::tryFrom(null));
    }
}
