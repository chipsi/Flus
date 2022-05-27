<?php

namespace flusio\cli;

use Minz\Response;
use flusio\models;
use flusio\services;

/**
 * @author  Marien Fressinaud <dev@marienfressinaud.fr>
 * @license http://www.gnu.org/licenses/agpl-3.0.en.html AGPL
 */
class Topics
{
    /**
     * List all the topics
     *
     * @response 200
     */
    public function index($request)
    {
        $topics = models\Topic::listAll();
        $presented_topics = [];
        foreach ($topics as $topic) {
            $presented_topics[] = $topic->id . ' ' . $topic->label;
        }

        return Response::text(200, implode("\n", $presented_topics));
    }

    /**
     * Create a topic.
     *
     * @request_param label
     * @request_param image_url An URL to an image to illustrate the topic (optional)
     *
     * @response 400 if the label is invalid
     * @response 200
     */
    public function create($request)
    {
        $label = $request->param('label');
        $image_url = $request->param('image_url');
        $topic = models\Topic::init($label);

        if ($image_url) {
            $image_service = new services\Image();
            $image_filename = $image_service->generatePreviews($image_url);
            $topic->image_filename = $image_filename;
        }

        $errors = $topic->validate();
        if ($errors) {
            $errors = implode(' ', $errors);
            return Response::text(400, "Topic creation failed: {$errors}");
        }

        $topic->save();

        return Response::text(200, "Topic {$topic->label} ({$topic->id}) has been created.");
    }

    /**
     * Update a topic.
     *
     * @request_param id
     * @request_param label
     * @request_param image_url An URL to an image to illustrate the topic (optional)
     *
     * @response 404 if the id doesn't exist
     * @response 400 if the label is invalid
     * @response 200
     */
    public function update($request)
    {
        $id = $request->param('id');
        $label = trim($request->param('label', ''));
        $image_url = trim($request->param('image_url', ''));

        $topic = models\Topic::find($id);
        if (!$topic) {
            return Response::text(404, "Topic id `{$id}` does not exist.");
        }

        if ($label) {
            $topic->label = $label;
        }

        if ($image_url) {
            $image_service = new services\Image();
            $image_filename = $image_service->generatePreviews($image_url);
            $topic->image_filename = $image_filename;
        }

        $errors = $topic->validate();
        if ($errors) {
            $errors = implode(' ', $errors);
            return Response::text(400, "Topic creation failed: {$errors}");
        }

        $topic->save();

        return Response::text(200, "Topic {$topic->label} ({$topic->id}) has been updated.");
    }

    /**
     * Delete a topic.
     *
     * @request_param id
     *
     * @response 404 if the id doesn't exist
     * @response 200
     */
    public function delete($request)
    {
        $id = $request->param('id');

        $topic = models\Topic::find($id);
        if (!$topic) {
            return Response::text(404, "Topic id `{$id}` does not exist.");
        }

        models\Topic::delete($topic->id);

        return Response::text(200, "Topic {$topic->label} ({$topic->id}) has been deleted.");
    }
}
