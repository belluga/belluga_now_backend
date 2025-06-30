<?php

namespace App\Http\Middleware;

use App\Models\Landlord\LandlordUser;
use Closure;

class LandlordValidation
{
    public function handle($request, Closure $next)
    {

        $user = auth()->guard('sanctum')->user();

        if(!$user){
            abort(401, "Unauthorized");
        }

        $class = get_class($user);

        $current_user_is_landlord = LandlordUser::class == $class;

        if(!$current_user_is_landlord) {
            abort(401, "Unauthorized");
        }

        return $next($request);
    }
}
