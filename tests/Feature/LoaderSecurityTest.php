<?php

namespace Ambengers\QueryFilter\Tests\Feature;

use Ambengers\QueryFilter\Tests\FeatureTest;
use Ambengers\QueryFilter\Tests\Filters\PostFilterInterface;
use Ambengers\QueryFilter\Tests\Filters\PostLoader;
use Ambengers\QueryFilter\Tests\Filters\PostMethodBasedFilters;
use Ambengers\QueryFilter\Tests\Models\Comment;
use Ambengers\QueryFilter\Tests\Models\Post;

class LoaderSecurityTest extends FeatureTest
{
    protected function setUp() : void
    {
        parent::setUp();

        app()->bind(PostFilterInterface::class, function ($app) {
            return new PostMethodBasedFilters(request());
        });
    }

    /** @test */
    public function it_does_not_load_relations_that_are_not_declared_in_loadables()
    {
        $this->withoutExceptionHandling();

        $post = factory(Post::class)->create(['subject' => 'foobar barbazz']);
        factory(Comment::class)->create(['post_id' => $post->id]);

        $response = $this->getJson(route('posts.index', ['load' => 'user']))
            ->assertSuccessful();

        $this->assertArrayNotHasKey('user', $response->json('0'));
    }

    /** @test */
    public function it_does_not_load_nested_relations()
    {
        $this->withoutExceptionHandling();

        $post = factory(Post::class)->create(['subject' => 'foobar barbazz']);
        $comment = factory(Comment::class)->create(['post_id' => $post->id]);

        $response = $this->getJson(route('posts.index', ['load' => 'comments.truncate']))
            ->assertSuccessful();

        $this->assertArrayNotHasKey('comments', $response->json('0'));

        $this->assertSame(1, Comment::count());
    }

    /** @test */
    public function it_does_not_run_truncate_as_a_relation_constraint()
    {
        $this->withoutExceptionHandling();

        $post = factory(Post::class)->create(['subject' => 'foobar barbazz']);

        factory(Comment::class, 3)->create(['post_id' => $post->id]);

        $this->getJson(route('posts.index', ['load' => 'comments|truncate']))
            ->assertSuccessful();

        $this->assertSame(
            3,
            Comment::count(),
            'A read-only request must never be able to truncate the related table.'
        );
    }

    /** @test */
    public function it_does_not_run_delete_as_a_relation_constraint()
    {
        $this->withoutExceptionHandling();

        $post = factory(Post::class)->create(['subject' => 'foobar barbazz']);

        factory(Comment::class, 3)->create(['post_id' => $post->id]);

        $this->getJson(route('posts.index', ['load' => 'comments|delete']))
            ->assertSuccessful();

        $this->assertSame(
            3,
            Comment::withTrashed()->count(),
            'A read-only request must never be able to delete the related rows.'
        );
    }

    /** @test */
    public function it_does_not_run_force_delete_as_a_relation_constraint()
    {
        $this->withoutExceptionHandling();

        $post = factory(Post::class)->create(['subject' => 'foobar barbazz']);

        factory(Comment::class, 3)->create(['post_id' => $post->id]);

        $this->getJson(route('posts.index', ['load' => 'comments|forceDelete']))
            ->assertSuccessful();

        $this->assertSame(
            3,
            Comment::withTrashed()->count(),
            'A read-only request must never be able to force delete the related rows.'
        );
    }

    /** @test */
    public function it_allows_model_query_scopes_as_relation_constraints()
    {
        $this->withoutExceptionHandling();

        $post = factory(Post::class)->create(['subject' => 'foobar barbazz']);

        $approved = factory(Comment::class)->create([
            'post_id' => $post->id,
            'approved_at' => now(),
        ]);

        factory(Comment::class)->create([
            'post_id' => $post->id,
            'approved_at' => null,
        ]);

        $response = $this->getJson(route('posts.show', [
            'post' => $post->id,
            'load' => 'comments|approved',
        ]))->assertSuccessful();

        $comments = $response->json('comments');

        $this->assertCount(1, $comments);
        $this->assertSame($approved->id, $comments[0]['id']);
    }

    /** @test */
    public function it_allows_constraints_declared_in_loadable_constraints()
    {
        $post = factory(Post::class)->create(['subject' => 'foobar barbazz']);

        factory(Comment::class)->create([
            'post_id' => $post->id,
            'created_at' => now()->subDays(3),
        ]);

        $newest = factory(Comment::class)->create([
            'post_id' => $post->id,
            'created_at' => now()->subDays(1),
        ]);

        // `latest` is not part of the default constraint allowlist, so it
        // only gets applied because it is declared here.
        $loader = new class(request()->merge(['load' => 'comments|latest'])) extends PostLoader {
            protected $loadableConstraints = [
                'comments' => ['latest'],
            ];
        };

        $post = $post->filter($loader);

        $this->assertSame($newest->id, $post->comments->first()->id);
    }

    /** @test */
    public function it_ignores_internal_package_methods_on_the_filter_class()
    {
        $this->withoutExceptionHandling();

        $post = factory(Post::class)->create(['subject' => 'foobar barbazz']);
        factory(Comment::class)->create(['post_id' => $post->id]);

        // "apply" is an internal package method and must not be invoked
        // from the query string.
        $this->getJson(route('posts.index', ['apply' => 'foo']))
            ->assertSuccessful()
            ->assertJsonFragment(['subject' => 'foobar barbazz']);
    }
}
