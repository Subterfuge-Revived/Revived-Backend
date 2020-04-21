<?php

namespace App\Http\Controllers;

use App\Http\Responses\DeletedResponse;
use App\Http\Responses\NotFoundResponse;
use App\Http\Responses\UpdatedResponse;
use App\Models\Event;
use App\Models\Room;
use App\Http\Responses\CreatedResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

class EventController extends Controller
{
    /**
     * Show a list of events.
     *
     * @param Request $request
     * @return Response
     * @throws ValidationException
     */
    public function index(Request $request)
    {
        $request->validate([
            'room_id' => 'required|int',
            'filter' => 'required|string', // Possible values: 'time', 'tick' but maybe others?
            'filter_arg' => 'required|string',
        ]);

        $room = Room::whereId($request->input('room_id'))->firstOrFail();

        if (!$room->players->contains($this->session->player)) {
            throw ValidationException::withMessages(['Player is not in the room']);
        }

        // TODO: We should change this API to accept multiple filters
        $eventsQuery = $room->events();
        if ($request->input('filter') === 'time') {
            $eventsQuery = $eventsQuery->where('created_at', '>=', $request->input('filter_arg'));
        }
        elseif ($request->input('filter') === 'tick') {
            $eventsQuery = $eventsQuery->where('occurs_at', '>=', $request->input('filter_arg'));
        }

        $events = $eventsQuery->get();

        return new Response($events->map(function (Event $event) {
            return [
                'event_id' => $event->id,
                'time_issued' => $event->created_at->unix(),
                'occurs_at' => $event->occurs_at->unix(),
                'player_id' => $event->player_id,
                'event_msg' => $event->event_json,
            ];
        }));
    }

    /**
     * Create an event.
     *
     * @param Request $request
     * @return Response
     * @throws ValidationException
     */
    public function store(Request $request)
    {
        $request->validate([
            'room_id' => 'required|int',
            'event_msg' => 'required|string',
            'occurs_at' => 'required|string',
        ]);

        json_decode($request->input('event_msg'));
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw ValidationException::withMessages(['Invalid event message: not valid JSON']);
        }

        $room = Room::whereId($request->input('room_id'))->firstOrFail();

        if (!$room->players->contains($this->session->player)) {
            throw ValidationException::withMessages(['Player is not in room']);
        }

        if (!$room->isOngoing()) {
            throw ValidationException::withMessages(['Room is not ongoing']);
        }

        $event = new Event([
            'occurs_at' => $request->input('occurs_at'),
            'event_json' => json_decode($request->input('event_msg')),
        ]);
        $event->player()->associate($this->session->player);
        $event->room()->associate($room);
        $event->save();

        return new CreatedResponse($event);
    }

    /**
     * Delete an event.
     *
     * @param Event $event
     * @return Response
     * @throws ValidationException|\Exception
     */
    public function destroy(Event $event)
    {
        if (!$event->player != $this->session->player) {
            throw ValidationException::withMessages(['Event does not belong to the given player']);
        }

        if (!$event->isModifiable()) {
            throw ValidationException::withMessages(['Event may not be modified']);
        }

        $event->delete();

        return new DeletedResponse($event);
    }

    /**
     * Update an event.
     *
     * @param Event $event
     * @param Request $request
     * @return Response
     * @throws ValidationException
     */
    public function update(Event $event, Request $request)
    {
        $request->validate([
            'room_id' => 'required|int',
            'event_msg' => 'required|string',
        ]);

        if (!$event->isModifiable()) {
            throw ValidationException::withMessages(['Event may not be modified']);
        }

        $event->event_json = json_decode($request->input('event_msg'));
        $event->save();

        return new UpdatedResponse($event);
    }

    /**
     * Show an event.
     *
     * @param Event $event
     * @return Response
     * @throws ValidationException
     */
    public function show(Event $event)
    {
        if ($event->player != $this->session->player) {
            throw ValidationException::withMessages(['Event does not belong to the given player']);
        }

        return new Response($event);
    }

}
