<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('authors', function (Blueprint $table) {
            $table->increments('id');
            $table->string('title');
            $table->string('name');
            $table->integer('age');
            $table->timestamps();
        });

        Schema::create('posts', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('author_id')->unsigned()->index();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('content')->nullable();
            $table->integer('votes')->default(0);
            $table->integer('views')->default(0);
            $table->foreign('author_id')->references('id')->on('authors')->onDelete('cascade')->onUpdate('cascade');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('replies', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('post_id')->unsigned()->index();
            $table->string('subject');
            $table->text('content');
            $table->foreign('post_id')->references('id')->on('posts')->onDelete('cascade')->onUpdate('cascade');
            $table->timestamps();
        });

        Schema::create('comments', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('post_id')->unsigned()->index();
            $table->string('title');
            $table->text('content');
            $table->date('date');
            $table->boolean('active')->default(false);
            $table->foreign('post_id')->references('id')->on('posts')->onDelete('cascade')->onUpdate('cascade');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('tags', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('post_tag', function (Blueprint $table) {
            $table->integer('post_id')->unsigned();
            $table->integer('tag_id')->unsigned();
            $table->integer('position')->default(0);
            $table->timestamps();
            $table->foreign('post_id')->references('id')->on('posts')->onDelete('cascade');
            $table->foreign('tag_id')->references('id')->on('tags')->onDelete('cascade');
            $table->primary(['post_id', 'tag_id']);
        });

        Schema::create('taggables', function (Blueprint $table) {
            $table->integer('tag_id')->unsigned();
            $table->morphs('taggable');
            $table->primary(['tag_id', 'taggable_id', 'taggable_type']);
            $table->foreign('tag_id')->references('id')->on('tags')->onDelete('cascade');
        });

        Schema::create('revisions', function (Blueprint $table) {
            $table->increments('id');

            $table->integer('user_id')->unsigned()->index()->nullable();
            $table->morphs('revisionable');
            $table->string('name')->nullable();
            $table->json('metadata')->nullable();
            $table->json('properties')->nullable();

            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('set null')->onUpdate('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('revisions');
        Schema::dropIfExists('taggables');
        Schema::dropIfExists('post_tag');
        Schema::dropIfExists('tags');
        Schema::dropIfExists('comments');
        Schema::dropIfExists('replies');
        Schema::dropIfExists('posts');
        Schema::dropIfExists('authors');
    }
};
