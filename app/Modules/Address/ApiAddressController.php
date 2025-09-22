<?php

namespace App\Modules\Address;

use App\Models\Address;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Request;
use App\Modules\Address\Services\AddressService;
use App\Modules\Address\Resources\AddressCollection;
use App\Modules\Address\Requests\StoreAddressRequest;
use App\Modules\Address\Requests\UpdateAddressRequest;
use App\Modules\Address\Requests\ListAllAddressesRequest;

class ApiAddressController extends Controller
{
    public function __construct(private AddressService $addressService)
    {
    }


    public function createAddress(StoreAddressRequest $request)
    {
       $user = $request->user();
    if (!$user) {
        Log::error('Unauthenticated user attempt');
        return response()->json(['success' => false, 'message' => 'Unauthenticated.'], 401);
    }


    $data = $request->validated();
    $data['user_id'] = $user->id;

    $Address = $this->addressService->createAddress($data);

        return successJsonResponse(new AddressCollection((collect([$Address]))), __('Address.success.create_Address'));
    }

   public function updateAddress($id, UpdateAddressRequest $request)
{
    $Address = $this->addressService->updateAddress($id, $request->validated());

    if (!$Address) {
        return response()->json(['error' => 'Address not found'], 404);
    }

  return successJsonResponse(
    new AddressCollection(collect([$Address])),
    __('Address.success.update_Address')
);

}


    public function deleteAddress($id)
    {
        $Address = $this->addressService->deleteAddress($id);
        if ($Address == true) {
            return successJsonResponse([], __('Address.success.delete_Address Address_id = ' . $Address['id']));
        } else {
            return errorJsonResponse("There is No Address with id = " . $id);
        }
    }

    public function listAllAddresses(ListAllAddressesRequest $request)
{
    $data = $request->validated();
    $data['user_id'] = $request->user()->id;

    $addresses = $this->addressService->listAllAddresses($data);

    return successJsonResponse(
        data_get($addresses, 'data'),
        __('Addresss.success.get_all_Addresss'),
        data_get($addresses, 'count')
    );
}
public function toggleDefault($id)
{
    $address = Address::findOrFail($id);

    // لو العنوان الحالي تابع لمستخدم
    $userId = $address->user_id;

    if ($address->is_default) {
        // لو هو default بالفعل، خليه false فقط
        $address->update(['is_default' => false]);
    } else {
        // أول حاجة: خلي كل العناوين للمستخدم false
        Address::where('user_id', $userId)->update(['is_default' => false]);

        // بعدين: خليه هو default
        $address->update(['is_default' => true]);
    }

    return response()->json([
        'message' => __('Address.success.toggle_default'),
        'data' => $address,
    ]);
}


    public function getAddressById($AddressId)
    {
        $Address = $this->addressService->getAddressById($AddressId);
        if (!$Address) {
            return errorJsonResponse("Address $AddressId is not found!", HttpStatusCodeEnum::Not_Found->value);
        }
        return successJsonResponse(new AddressCollection($Address), __('Address.success.Address_details'));
    }


}
