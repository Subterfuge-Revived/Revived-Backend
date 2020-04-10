<?php

namespace App\Http\Controllers;

use App\Models\PlayerRoom;
use App\Models\PlayerSession;
use App\Models\Room;
use Carbon\Carbon;
use Illuminate\Contracts\Routing\ResponseFactory;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

class RoomController extends Controller
{

    /**
     * RoomController constructor.
     */
    public function __construct()
    {
        parent::__construct();

        // Enforce API authentication for each request.
        $this->middleware('auth.api');
    }

    /**
     * Show a room.
     *
     * @param int $roomId
     * @param Request $request
     * @return ResponseFactory|Response|object
     */
    public function show(int $roomId, Request $request)
    {
        // TODO: Determine whether a user must be authenticated to use this API.

        if (!$room = Room::whereId($roomId)->first()) {
            return response('')->setStatusCode(404);
        }

        return response($room);
    }

    /**
     * @param Request $request
     * @return Validator
     */
    public function validator(Request $request)
    {
        return \Validator::make($request->all(), [
            'max_players' => 'required|int|between:2,10',
            'goal' => 'required|int', // FIXME: We should not require the client to send goal IDs but rather identifiers
            'description' => 'required|string',
            'map' => 'required|int|between:0,3',
            'min_rating' => 'required|int|min:0', // TODO: Make this optional for open games?
            'rated' => 'required|boolean',
            'anonymity' => 'required|boolean',
        ]);
    }

    /**
     * Create a new room.
     *
     * @param Request $request
     * @return JsonResponse|Response
     * @throws ValidationException
     */
    public function store(Request $request)
    {
        $this->validator($request)->validate();

        $minRating = $request->get('rated')
            ? $request->get('min_rating')
            : 0;

        if ($this->session->player->rating < $minRating) {
            throw ValidationException::withMessages(['Invalid minimum rating']);
        }

        // Create the room and associate it with its creator
        /** @var Room $room */
        $room = $this->session->player->created_rooms()->save(new Room([
            'goal_id' => $request->get('goal'),
            'description' => $request->get('description'),
            'is_rated' => $request->get('rated'),
            'is_anonymous' => $request->get('anonymity'),
            'max_players' => $request->get('max_players'),
            'min_rating' => $minRating,
            'map' => $request->get('map'),
            'seed' => Carbon::now()->unix(),
        ]));

        // Add the creator to the room
        $playerRoom = new PlayerRoom();
        $playerRoom->player()->associate($this->session->player);
        $playerRoom->room()->associate($room);

        dd(' success' );
        return response([
            'success' => true,
            'created_room' => [
                'room_id' => $room->id,
                'creator' => $this->session->player->id,
                'description' => $room->description,
                'rated' => (bool)$room->is_rated,
                'max_players' => $room->max_players,
                'player_count' => 1, // TODO: This is always only the creator, do we really need to return this?
                'min_rating' => $room->min_rating,
                'goal' => $room->goal_id,
                'anonymity' => (bool)$room->is_anonymous,
                'map' => $room->map,
                'seed' => $room->seed,
            ],
        ]);
    }

    /**
     * Update a room.
     *
     * @param $roomId
     * @param Request $request
     * @return ResponseFactory|Response|object
     */
    public function update($roomId, Request $request)
    {
        $this->validator($request)->validate();
        if (!$room = Room::whereId($roomId)->first()) {
            return response('')->setStatusCode(404);
        }

        $minRating = $request->get('rated')
            ? $request->get('min_rating')
            : 0;

        $room->update([
            'goal_id' => $request->get('goal'),
            'description' => $request->get('description'),
            'is_rated' => $request->get('rated'),
            'is_anonymous' => $request->get('anonymity'),
            'max_players' => $request->get('max_players'),
            'min_rating' => $minRating,
            'map' => $request->get('map'),
            'seed' => Carbon::now()->unix(),
        ]);

        return response($room);
    }

    /**
     * Destroy a room.
     *
     * @param $roomId
     * @param Request $request
     * @return ResponseFactory|Response|object
     * @throws \Exception
     */
    public function destroy($roomId, Request $request)
    {
        if (!$room = Room::whereId($roomId)->first()) {
            return response('')->setStatusCode(404);
        }

        // Only the creator of a room can destroy it. Return unauthorized otherwise.
        if (!$room->creator_player == $this->session->player) {
            return response('')->setStatusCode(401);
        }

        $room->delete();

        return response('')->setStatusCode(204);
    }
}
