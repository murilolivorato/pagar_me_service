<?php


namespace Customer;

use Carbon\Carbon;
use Illuminate\Http\Request;
use LeilaoMax\Entities\User;
use LeilaoMax\Entities\Usercard;
use PagarMeService;

class CheckOutController extends Controller
{


    public function loadCheckoutDetales(Request $request)
    {
        $user_id = User::find($this->customerBuyerUser->id)->id;

        // Carbon::setLocale(config('app.locale'));
        $plan = Plan::where('url_title', $request['plan'])->first();
        if(!$plan) {
            return;
        }
        $planData =  [
            'name'       => $plan->name,
            'trial_days' => $plan->trial_days,
            'amount'     => $plan->amount,
            'code'       => $plan->code,
            'today'      => str_replace("-", " de ", Carbon::now()->translatedFormat('d - F - Y')),
            'next_year'  => str_replace("-", " de ", Carbon::now()->addDay($plan->days)->translatedFormat('d - F - Y'))
        ];
        $plan_code = $plan->code;
        $billetTransaction =  TransactionBillet::select([ 'id' , 'boleto_url'])
                                               ->with([ 'Transaction' => function($query) {
                                                   $query->select('id' , 'importable_id' , 'importable_type' , 'user_id');
                                               } ])->whereHas('Transaction', function ($query) use ($user_id, $plan_code) {
                return $query->where('user_id', $user_id )->where('plan_code', $plan_code);
            })->first();

        $billetTransaction = $billetTransaction ? [ 'payment_type' => [$billetTransaction['transaction']['payment_type']],
                                                    'billet_utl'   => $billetTransaction['boleto_url'] ] : ['payment_type' => [] ];


        $cardTransaction = TransactionCard::select([ 'id'])
                                          ->with([ 'Transaction' => function($query) {
                                              $query->select('id' , 'importable_id' , 'importable_type' , 'user_id');
                                          } ])->whereHas('Transaction', function ($query) use ($user_id, $plan_code) {
                return $query->where('user_id', $user_id )->where('plan_code', $plan_code);
            })->first();

        $cardTransaction = $cardTransaction ? [ 'payment_type' => [$cardTransaction['transaction']['payment_type'] ]] : ['payment_type' => [] ];
        $transaction = ['payment_type' => array_merge($cardTransaction['payment_type'], $billetTransaction['payment_type']),
                        'billet_utl'   => array_key_exists( 'billet_utl', $billetTransaction) ? $billetTransaction['billet_utl'] : null];

        return response()->json(['plan' => $planData,
                                 'transaction' => $transaction] , 200);


    }


    public function getCardsTest(Request $request)
    {
        $user = User::find(788);
        if(!$user){
            return response()->json(null , 200);
        }
        if(!isset($user->subscriber->user_code)){
            return response()->json(null , 200);
        }
        return response()->json(Usercard::select('card_id', 'brand', 'last_digits', 'holder_name', 'user_id')->where('user_id', $user->id)->orderBy('created_at', 'DESC')->get(), 200);

        // return response()->json($pagarMeService->getCreditCards($user->subscriber->gateway_reference_id) , 200);

    }

    public function getCards(Request $request)
    {
        $user = User::find($this->customerBuyerUser->id);
        if(!$user){
            return response()->json([] , 200);
        }
        if(!isset($user->subscriber->user_code)){
            return response()->json([] , 200);
        }
        $card = Usercard::select('card_id', 'brand', 'last_digits', 'holder_name', 'user_id')->where('user_id', $user->id)->orderBy('created_at', 'DESC')->get();
        return response()->json($card, 200);

       // return response()->json($pagarMeService->getCreditCards($user->subscriber->gateway_reference_id) , 200);

    }
  
    public function storeCards(StoreCardRequest $request)
    {
        $pagarMeService = new PagarMeService();
        $user = User::find($this->customerBuyerUser->id);

            DB::beginTransaction();
            $customer = self::getCustomer($pagarMeService, $user);
            if (isset($customer['errors'])) {
                $errors = collect($customer['errors'])->pluck('message');
                return response()->json($errors ,  400 );
            }
            // CREATE CARD IN PAGARME
            $card = $pagarMeService->createCreditCard($customer['id'],
                                                      $request['card_number'],
                                                      $request['expiration_date'],
                                                      SetCharacter::makeLowercase($request['card_holder_name']),
                                                      $request['card_cvv']);
            if (isset($card['errors'])) {
                $errors = collect($card['errors'])->pluck('message');
                return response()->json($errors, 400);
            }
            // HAS NOT CARD ID
            if(! $card['id']) {
                return response()->json('has an error', 400);
            }

            // INSERT DB
            $user->UserCard()->create(
                [
                    'card_id'     => $card['id'],
                    'brand'       => $card['brand'],
                    'last_digits' => $card['last_digits'],
                    'holder_name' => SetCharacter::makeLowercase($card['holder_name'])
                ]
            );
            DB::commit();
            // RETURN ERROR DATA
            return response()->json([ 'card_id'     => $card['id'], 'brand'       => $card['brand'], 'last_digits' => $card['last_digits'], 'holder_name' => $card['holder_name']], 200);

    }

    public function destroyCard(Request $request)
    {

        $user = User::find($this->customerBuyerUser->id);
        $card = Usercard::where('card_id', $request['card_id'])->first();

        if(! $user){
            return;
        }

        if(! $card){
            return;
        }

        // IF IS NOT THE OWNER RETURN
        if($user->id !== $card->user_id) {
            return;
        }

        // DELETE CARD
        $card->delete();
        return response()->json(['index'   => $request['index']  ] , 200);
    }

    public function cancelSubscription ($user, $pagarMeService) {
        if(! $user->subscriber->subscription_code) {
            return;
        }
        $canceSubscription =  $pagarMeService->cancelSubscription($user->subscriber->subscription_code);
        if($canceSubscription['status'] === 'canceled') {
            $user->subscriber()->update([
                                            'subscription_code' => null,
                                            'plan_id' => null,
                                            'status' => 'canceled'
                                        ]);
        }
        return true;
    }

    public function planStoreCard(Request $request)
    {
        $pagarMeService = new PagarMeService();
        $plan = Plan::where('code', $request['plan_id'])->first();

        if (is_null($plan)) {
            return response()->json('Plano não encontrado.' , 400);
        }
        $user = User::find($this->customerBuyerUser->id);
        // VALIDATE THE DATA
        $dataErrors = self::validateUserData($user);
        if($dataErrors) {
            return response()->json($dataErrors, 422);
        }

        $customer = self::getCustomer($pagarMeService, $user);
        $customerAll = self::customerAll($customer, $user);
        try {
           /* if($user->subscriber->subscription_code) {}*/
            DB::beginTransaction();

            // SET SUBSCRIPTION CODE
            $susbscription_code = $user->subscriber->subscription_code;
            // CANCELL SUBSCRIPTION IF HAS OTHER SUBSCRIPTION
            if($user->subscriber->subscription_code) {
                if($plan->id !== $user->subscriber->plan_id) {
                     // CANCELL SUBSCRIPTION
                     $this->cancelSubscription($user, $pagarMeService);
                     $susbscription_code = null;
                }
            }

            // MAKE SUBSCRIPTION
            $subscription = $susbscription_code ?  json_decode(json_encode($pagarMeService->updateeSubscription($user->subscriber->subscription_code, $plan->code, 'credit_card', $request['card_id'])), true)
                                                :  json_decode(json_encode($pagarMeService->createSubscription($customerAll, $plan->code, 'credit_card',  $request['card_id'])), true);

            if (isset($subscription['errors'])) {
                return response()->json($subscription['errors'] , 422);
            }
            $user->trial = 0;
            $user->save();
            $user->subscriber()->update([
                                           'subscription_code' => $subscription['id'],
                                           'plan_id' => $plan->id,
                                           'status' => $subscription['status']
                                       ]);

            if (isset($subscription['current_transaction']['id'])) {
                $transctionCard                    = new TransactionCard();
                $transctionCard->card_holder_name  = $subscription['current_transaction']['card_holder_name'];
                $transctionCard->card_last_digits  = $subscription['current_transaction']['card_last_digits'];
                $transctionCard->card_first_digits = $subscription['current_transaction']['card_first_digits'];
                $transctionCard->card_brand        = $subscription['current_transaction']['card_brand'];
                $transctionCard->save();
                $transctionCard->Transaction()->create([    'transaction_code'   => $subscription['current_transaction']['id'],
                                                            'status'             => $subscription['current_transaction']['status'],
                                                            'authorization_code' => $subscription['current_transaction']['authorization_code'],
                                                            'amount'             => $subscription['current_transaction']['amount'],
                                                            'authorized_amount'  => $subscription['current_transaction']['authorized_amount'],
                                                            'paid_amount'        => $subscription['current_transaction']['paid_amount'],
                                                            'refunded_amount'    => $subscription['current_transaction']['refunded_amount'],
                                                            'installments'       => $subscription['current_transaction']['installments'],
                                                            'cost'               => $subscription['current_transaction']['cost'],
                                                            'subscription_code'  => $subscription['current_transaction']['subscription_id'],
                                                            'postback_url'       => $subscription['current_transaction']['postback_url'],
                                                            'plan_code'          => $plan->code,
                                                            'date_payment'       => Carbon::now()->format('Y-m-d H:i:s'),
                                                            'user_id'            => $user->id
                                                       ]);

            }

            DB::commit();
            // SUCCESS
            return response()->json(null , 200);
        } catch (RequestException $e) {
            if ($e->hasResponse()){
                $response = $e->getResponse();
                if ($response->getStatusCode() == '400') {
                    $errors = self::subscriberErrors([json_decode($response->getBody()->getContents(), true)]);
                    return response()->json([ 'errors' => $errors] , 422);
                }
            }
        } catch (GuzzleHttp\Exception\ClientException $e) {
            DB::rollback();
            $response = $e->getResponse();
            return response()->json([ 'message' => $response->getBody()->getContents()] , 422);
        }
    }


    public function planStoreBillet(CheckoutProcessBilletRequest $request)
    {
        $pagarMeService = new PagarMeService();
        $plan = Plan::where('code', $request['plan_id'])->first();

        if (is_null($plan)) {
            return response()->json('Plano não encontrado.' , 400);
        }


        $user = User::find($this->customerBuyerUser->id);
        // VALIDATE THE DATA
        $dataErrors = self::validateUserData($user);
        if($dataErrors) {
            return response()->json(['errors' => $dataErrors], 422);
        }

        // UPDATE PLAN IF THE PLAN IS DIFFERENT
        if($user->subscriber->user_code) {
            if($plan->code !== $user->subscriber->user_code) {

            }
        }

        $customer = self::getCustomer($pagarMeService, $user);
        $customerAll = self::customerAll($customer, $user);

        try {
            DB::beginTransaction();

            // SET SUBSCRIPTION CODE
            $susbscription_code = $user->subscriber->subscription_code;
            // CANCELL SUBSCRIPTION IF HAS OTHER SUBSCRIPTION
            if($user->subscriber->subscription_code) {
                if($plan->id !== $user->subscriber->plan_id) {
                    // CANCELL SUBSCRIPTION
                    $this->cancelSubscription($user, $pagarMeService);
                    $susbscription_code = null;
                }
            }

            // MAKE SUBSCRIPTION
            $subscription = $susbscription_code ? json_decode(json_encode($pagarMeService->updateeSubscription($susbscription_code, $plan->code, 'boleto')), true)
                                                : json_decode(json_encode($pagarMeService->createSubscription($customerAll, $plan->code, 'boleto')), true);


            if (isset($subscription['errors'])) {
                $errors = collect($subscription['errors'])->pluck('message');
                return response()->json($errors , 422);
            }

            $user->subscriber()->update([
                                            'subscription_code'    => $subscription['id'],
                                            'plan_id'              => $plan->id,
                                            'status'               => $subscription['status']
                                        ]);


            $transactionBillet                         = new TransactionBillet();
            $transactionBillet->boleto_url             = $subscription['current_transaction']['boleto_url'];
            $transactionBillet->boleto_barcode         = $subscription['current_transaction']['boleto_barcode'];
            $transactionBillet->boleto_expiration_date = date('Y-m-d H:i:s', strtotime($subscription['current_transaction']['boleto_expiration_date']));
            $transactionBillet->save();
            $transactionBillet->Transaction()->create([  'transaction_code'   => $subscription['current_transaction']['id'],
                                                         'status'             => $subscription['current_transaction']['status'],
                                                         'authorization_code' => $subscription['current_transaction']['authorization_code'],
                                                         'amount'             => $subscription['current_transaction']['amount'],
                                                         'authorized_amount'  => $subscription['current_transaction']['authorized_amount'],
                                                         'paid_amount'        => $subscription['current_transaction']['paid_amount'],
                                                         'refunded_amount'    => $subscription['current_transaction']['refunded_amount'],
                                                         'installments'       => $subscription['current_transaction']['installments'],
                                                         'cost'               => $subscription['current_transaction']['cost'],
                                                         'subscription_code'  => $subscription['current_transaction']['subscription_id'],
                                                         'postback_url'       => $subscription['current_transaction']['postback_url'],
                                                         'date_payment'       => Carbon::now()->format('Y-m-d H:i:s'),
                                                         'plan_code'          => $plan->code,
                                                         'user_id'            => $user->id
                                                   ]);

            DB::commit();
            // SUCCESS
            return response()->json(['billet_utl' => $transactionBillet['boleto_url']] , 200);
        } catch (RequestException $e) {
            if ($e->hasResponse()){
                $response = $e->getResponse();
                if ($response->getStatusCode() == '400') {
                    $errors = self::subscriberErrors([json_decode($response->getBody()->getContents(), true)]);
                    return response()->json([ 'errors' => $errors] , 422);
                }
            }
        } catch (GuzzleHttp\Exception\ClientException $e) {
            DB::rollback();
            $response = $e->getResponse();
            return response()->json([ 'message' => $response->getBody()->getContents()] , 422);
        }
    }


    private static  function customerAll($customer, $user)
    {
       return ['name'            => $customer['name'],
               'email'           => $customer['email'],
               'document_number' => $customer['documents'][0]->number,
               'address'         => ['street'        => $user->Address->street,
                                     'street_number' => $user->Address->number,
                                     'complementary' => $user->Address->complement,
                                     'neighborhood'  => $user->Address->neighborhood,
                                     'zipcode'       => $user->Address->zip_code],
               'phone'            => ['ddd'          => substr($user->subscriber->cellphone, 0, 2),
                                      'number'       => substr($user->subscriber->cellphone, -9) ],
               'gender'           => $user->subscriber->gender == "male" ? "masculino" : "feminino",
               'born_at'          => $user->subscriber->birth_date
        ];
    }


    protected static function getCustomer(PagarMeService $pagarMeService, User $user) {
        // IF IS CUSTOMER
        if ($user->subscriber->user_code) {
            return $pagarMeService->getCustomer($user->subscriber->user_code);
        }

        $phone_numbers = [sprintf('%s%s', '+55', $user->subscriber->cellphone)];
        $documents = [
            [
                'type' => $user->subscriber->type_person == 'person' ? 'cpf' : 'cnpj',
                'number' => $user->subscriber->cpf_cnpj
            ]
        ];
        $type = $user->subscriber->type_person == 'person' ?  'individual' : 'corporation';

        return $pagarMeService->createCustomer($user->subscriber->name, $user->email, $user->id, $phone_numbers, $documents, $type);

    }

    protected static  function validateUserData(User $user) {
        $subscriber_error = [];
        $address_error = [];

        // VALIDATE SUBSCIBER
        $subscriber = Subscriber::select('name','cellphone','cpf_cnpj', 'type_person', 'birth_date', 'gender')->where('user_id', $user->id)->first()->toArray();
        $collection = collect($subscriber)->flatMap(function ($item, $index) {
            return [
                "info_".$index        => $item,
            ];
        })->toArray();
        $subscriberValidation = Validator::make($collection, [
            'info_name'            => 'required|min:6|max:50',
            'info_cellphone'       => 'required|min:4|max:50',
            'info_cpf_cnpj'        => 'required|min:4|max:50',
            'info_type_person'     => 'required|min:1',
            'info_birth_date'      => 'required|min:2|max:20',
            'info_gender'          => 'required|min:1'
        ]);
        if ($subscriberValidation->fails()) {
            $subscriber_error = $subscriberValidation->errors();
            //  return response()->json($subscriberValidation, 422);
        }

        // VALIDATE ADDRESS
        $address = SubscriberAddress::select('street','number','zip_code','neighborhood','city','state','country')->where('user_id', $user->id)->first();
        $address =  $address ? $address->toArray() : null;
        if(!$address) {
            $address = ['street' => null,
                        'number' => null,
                        'zip_code' => null,
                        'neighborhood' => null,
                        'city' => null,
                        'state' => null,
                        'country' => null];
        }

        $collection = collect($address)->flatMap(function ($item, $index) {
            return [
                "address_".$index        => $item,
            ];
        })->toArray();

        $addresValidation =  Validator::make($collection, [
            'address_street'       => 'required|min:3|max:50',
            'address_number'       => 'required|min:1',
            'address_zip_code'     => 'required|min:3|max:32',
            'address_neighborhood' => 'required|min:2|max:32',
            'address_city'         => 'required|min:3|max:100',
            'address_state'        => 'required|min:1|max:32',
            'address_country'      => 'required|min:1|max:32'
        ]);

        if ($addresValidation->fails()) {
            $address_error = $addresValidation->errors();
        }

        $collection = collect($subscriber_error);
        $merged = $collection->merge($address_error);

        if(count($merged)) {
            return $merged->all();
        }
    }


    protected static function createCardErrors($data) {
        $errorList = [
            'card_number'          => 'card_number',
            'card_holder_name'     => 'card_holder_name',
            'card_expiration_date' => 'expiration_date',
            'card_cvv'             => 'card_cvv'
        ];
         if (! array_key_exists(0 ,$data)) {
            return [];
        }
        if (! array_key_exists('errors', $data[0])) {
            return [];
        }
        $list = [];
        $has_in_list = false;
        foreach ($data[0]['errors'] as $key => $error) {
            foreach ($errorList as $key => $newErrorName) {
                // array_push($list, [$error['parameter_name'] => [$error['message']]]);
                if (strpos($error['parameter_name'], $key) !== FALSE) {
                    $list[$newErrorName] = [$error['message']];
                    $has_in_list = true;
                    continue;
                }
            }
            if(!$has_in_list) {
                $list['general'] = [$error['message']];
            }
        }

        return $list;
    }


    protected static function subscriberErrors($data) {

       $errorList = [
           'customer[address][street]'        => 'address_street',
           'customer[address][street_number]' => 'address_number',
           'customer[address][complementary]' => 'address_complementary',
           'customer[address][neighborhood]'  => 'address_neighborhood',
           'customer[address][city]'          => 'address_city',
           'customer[address][state]'         => 'address_state',
           'customer[address][country]'       => 'address_country',
           'customer[address][zipcode]'       => 'address_zip_code',
           'customer[phone][ddd]'             => 'phone_ddd',
           'customer[phone][number]'          => 'phone_number',
           'customer[gender]'                 => 'info_gender',
           'customer[born_at]'                => 'info_birth_date',
           'customer[email]'                  => 'info_email',
           'customer[name]'                   => 'info_name',
           'customer[document_number]'        => 'info_cpf_cnpj',
           'plan_id'                          => 'plan_id',
           'payment_method'                   => 'payment_method',
           'card_number'                      => 'card_number',
           'card_holder_name'                 => 'card_holder_name',
           'card_expiration_date'             => 'card_expiration_date',
           'card_cvv'                         => 'card_cvv'
       ];

        if (! array_key_exists(0 ,$data)) {
            return [];
        }
        if (! array_key_exists('errors', $data[0])) {
            return [];
        }
        $list = [];
        $has_in_list = false;
        foreach ($data[0]['errors'] as $key => $error) {
            foreach ($errorList as $key => $newErrorName) {
                // array_push($list, [$error['parameter_name'] => [$error['message']]]);
                 if (strpos($error['parameter_name'], $key) !== FALSE) {
                     $list[$newErrorName] = [$error['message']];
                     $has_in_list = true;
                    continue;
                }
            }
            if(!$has_in_list) {
                $list['general'] = [$error['message']];
            }

        }

        return $list;
    }


}
