<?php

    namespace App\Http\Controllers;

    use App\Models\Tenant\Configuration;
    use App\Models\Tenant\Item;
    use Illuminate\Database\Query\Builder;
    use Illuminate\Http\Request;
    use Illuminate\Support\Collection;
    use Modules\Inventory\Models\Warehouse as ModuleWarehouse;

    /**
     * Tener en cuenta como base modules/Document/Traits/SearchTrait.php
     * Class SearchItemController
     *
     * @package App\Http\Controllers
     * @mixin Controller
     */
    class SearchItemController extends Controller
    {


        /**
         * @param Request|null $request
         *
         * @return Item[]|\Illuminate\Database\Eloquent\Builder[]|\Illuminate\Database\Eloquent\Collection|Builder[]|Collection|mixed
         */
        public static function getAllItem(Request $request = null)
        {


            self::validateRequest($request);
            return self::getNotServiceItem($request);
        }

        /**
         * @param Request|null $request
         */
        protected static function validateRequest(&$request)
        {
            if ($request == null) $request = new Request();

        }

        /**
         * @param Request|null $request
         * @param int          $id
         *
         * @return \Illuminate\Database\Eloquent\Builder[]|\Illuminate\Database\Eloquent\Collection
         */
        public static function getNotServiceItem(Request $request = null, $id = 0)
        {

            self::validateRequest($request);
            $search_by_barcode = $request->has('search_by_barcode') && (bool)$request->search_by_barcode;
            $input = self::setInputByRequest($request);
            $item = self::getAllItemBase($request, false, $id);

            if ($search_by_barcode === false && $input != null) {
                self::SetWarehouseToUser($item);
            }


            return $item->orderBy('description')->get();
        }

        /**
         * Busca la propiedad input o input_item para generar busquedas
         *
         * @param Request|null $request
         *
         * @return mixed|null
         */
        protected static function setInputByRequest(Request $request = null)
        {
            if (!empty($request)) {
                $input = ($request->has('input')) ? $request->input : null;
                if (empty($input) && $request->has('input_item')) {
                    $input = ($request->has('input_item')) ? $request->input_item : null;
                }
            }
            return $input;
        }

        /**
         * @param Request|null $request
         *
         * @return \Illuminate\Database\Eloquent\Builder
         */
        public static function getAllItemBase(Request $request = null, $service = false, $id = 0)
        {

            self::validateRequest($request);
            $search_item_by_series = Configuration::first()->isSearchItemBySeries();

            $items_id = ($request->has('items_id')) ? $request->items_id : null;
            $id = (int)$id;
            $search_by_barcode = $request->has('search_by_barcode') && (bool)$request->search_by_barcode;

            $input = self::setInputByRequest($request);

            $item = Item:: whereIsActive()//    ->whereTypeUser()
            ;
            $ItemToSearchBySeries = Item:: whereIsActive();
            if ($service == false) {
                $item->WhereNotService()
                    ->with('warehousePrices');
                $ItemToSearchBySeries->WhereNotService()
                    ->with('warehousePrices');
            } else {
                $item
                    ->WhereService()
                    // ->with(['item_lots'])
                    ->whereNotIsSet();
                $ItemToSearchBySeries
                    ->WhereService()
                    // ->with(['item_lots'])
                    ->whereNotIsSet();


            }

            $alt_item = $item;

            $bySerie = null;
            if ($search_item_by_series == true) {
                self::validateRequest($request);
                $warehouse = ModuleWarehouse::select('id')->where('establishment_id', auth()->user()->establishment_id)->first();
                $input = self::setInputByRequest($request);
                if (!empty($input)) {

                    $ItemToSearchBySeries->WhereHas('item_lots', function ($query) use ($warehouse, $input) {
                        $query->where('has_sale', false);
                        $query->where('warehouse_id', $warehouse->id);
                        $query->where('series', $input);
                        // return $query;
                    })->take(1);

                    //Busca el item con relacion al almacen
                     self::SetWarehouseToUser($item);
                     self::SetWarehouseToUser($ItemToSearchBySeries);
                    $bySerie = $ItemToSearchBySeries->first();
                    if ($bySerie !== null) {
                        //Si existe un dato, devuelve la busqueda por serie.
                        $item->WhereHas('item_lots', function ($query) use ($warehouse, $input) {
                            $query->where('has_sale', false);
                            $query->where('warehouse_id', $warehouse->id);
                            $query->where('series', $input);
                        })->take(1);


                    }
                }
            }
            if ($bySerie === null) {
                if ($items_id != null) {
                    $item->whereIn('id', $items_id);
                } elseif ($id != 0) {
                    $item->where('id', $id);
                } else {


                    if ($search_by_barcode === true) {
                        $item
                            ->where('barcode', $input)
                            ->limit(1);
                    } else {
                        self::setFilter($item, $request);
                        $item->take(20);
                    }
                }
            }

            return $item->orderBy('description');
        }

        /**
         * Establece que solo se mostraria los item donde el usuario se encuentra
         *
         * @param $item
         */
        public static function SetWarehouseToUser(&$item)
        {
            /** @var Item $item */
            // $item->whereWarehouse();

        }

        protected static function setFilter(&$item, Request $request = null)
        {

            $input = self::setInputByRequest($request);

            if (!empty($input)) {
                $whereItem[] = ['description', 'like', '%' . $input . '%'];
                $whereItem[] = ['internal_id', 'like', '%' . $input . '%'];
                $whereItem[] = ['barcode', '=', $input];
                $whereExtra[] = ['name', 'like', '%' . $input . '%'];

                foreach ($whereItem as $index => $wItem) {
                    if ($index < 1) {
                        $item->Where([$wItem]);
                    } else {
                        $item->orWhere([$wItem]);
                    }
                }

                if (!empty($whereExtra)) {
                    $item
                        ->orWhereHas('brand', function ($query) use ($whereExtra) {
                            $query->where($whereExtra);
                        })
                        ->orWhereHas('category', function ($query) use ($whereExtra) {
                            $query->where($whereExtra);
                        });
                }
                $item->OrWhereJsonContains('attributes', ['value' => $input]);
                /** @var Builder $item */
            }


        }

        /**
         * @param Request|null $request
         *
         * @return \Illuminate\Database\Eloquent\Collection|Collection
         */
        public static function getNotServiceItemToModal(Request $request = null, $id = 0)
        {
            $establishment_id = auth()->user()->establishment_id;
            $warehouse = ModuleWarehouse::where('establishment_id', $establishment_id)->first();
            self::validateRequest($request);
            return self::getNotServiceItem($request, $id)->transform(function ($row) use ($warehouse) {
                /** @var Item $row */

                return $row->getDataToItemModal($warehouse);
            });
        }

        /**
         * Reaqliza una busqueda de item por id, Intenta por item, luego por servicio
         * Devuelve un standar de modal
         *
         * @param int $id
         *
         * @return \Illuminate\Database\Eloquent\Collection|Collection
         */
        public static function searchByIdToModal($id = 0)
        {
            $establishment_id = auth()->user()->establishment_id;
            $warehouse = ModuleWarehouse::where('establishment_id', $establishment_id)->first();

            $items = self::searchById($id)->transform(function ($row) use ($warehouse) {
                /** @var Item $row */
                return $row->getDataToItemModal(
                    $warehouse,
                    true,
                    null,
                    false,
                    true
                );

            });
            return $items;
        }

        /**
         * @param int $id
         *
         * @return Item[]|\Illuminate\Database\Eloquent\Builder[]|\Illuminate\Database\Eloquent\Collection|Builder[]|Collection|mixed
         */
        public static function searchById($id = 0)
        {
            $search_item = self::getNotServiceItem(null, $id);
            if (count($search_item) == 0) {
                $search_item = self::getServiceItem(null, $id);

            }
            return $search_item;
        }

        /**
         * @param Request|null $request
         * @param int          $id
         *
         * @return Item[]|\Illuminate\Database\Eloquent\Builder[]|\Illuminate\Database\Eloquent\Collection|Builder[]|Collection|mixed
         */
        public static function getServiceItem(Request $request = null, $id = 0)
        {
            self::validateRequest($request);
            $search_by_barcode = $request->has('search_by_barcode') && (bool)$request->search_by_barcode;
            $input = self::setInputByRequest($request);
            /** @var Item $item */
            $item = self::getAllItemBase($request, true, $id);

            if ($search_by_barcode === false && $input != null) {
                self::SetWarehouseToUser($item);
            }


            return $item->orderBy('description')->get();

        }

        /**
         * @param Request $request
         *
         * @return Item[]|\Illuminate\Database\Eloquent\Builder[]|\Illuminate\Database\Eloquent\Collection|Builder[]|Collection|mixed
         */
        public static function searchByRequest(Request $request)
        {
            $search_item = self::getNotServiceItem($request);
            if (count($search_item) == 0) {
                $search_item = self::getServiceItem($request);

            }
            return $search_item;
        }

        /**
         * @param int $id
         *
         * @return Item[]|\Illuminate\Database\Eloquent\Builder[]|\Illuminate\Database\Eloquent\Collection|Builder[]|Collection|mixed
         */
        public static function searchByIdToPurchase($id = 0)
        {
            $search_item = self::getNotServiceItemToPurchase(null, $id);
            if (count($search_item) == 0) {
                $search_item = self::getServiceItemToPurchase(null, $id);

            }
            return $search_item;
        }

        /**
         * Devuelve el conjunto para ventas sin los pack o productos compuestos
         *
         * @param Request|null $request
         * @param int          $id
         *
         * @return \Illuminate\Database\Eloquent\Builder[]|\Illuminate\Database\Eloquent\Collection
         */
        public static function getNotServiceItemToPurchase(Request $request = null, $id = 0)
        {

            self::validateRequest($request);
            $search_by_barcode = $request->has('search_by_barcode') && (bool)$request->search_by_barcode;
            $input = self::setInputByRequest($request);

            $item = self::getAllItemBase($request, false, $id);

            $item->WhereNotIsSet();


            if ($search_by_barcode === false && $input != null) {
                self::SetWarehouseToUser($item);
            }


            return $item->orderBy('description')->get();
        }

        /**
         * @param Request|null $request
         * @param int          $id
         *
         * @return Item[]|\Illuminate\Database\Eloquent\Builder[]|\Illuminate\Database\Eloquent\Collection|Builder[]|Collection|mixed
         */
        public static function getServiceItemToPurchase(Request $request = null, $id = 0)
        {
            self::validateRequest($request);
            $search_by_barcode = $request->has('search_by_barcode') && (bool)$request->search_by_barcode;
            $input = self::setInputByRequest($request);
            /** @var Item $item */
            $item = self::getAllItemBase($request, true, $id);
            $item->WhereNotIsSet();

            if ($search_by_barcode === false && $input != null) {
                self::SetWarehouseToUser($item);
            }


            return $item->orderBy('description')->get();

        }

        /**
         * @param Request $request
         *
         * @return Item[]|\Illuminate\Database\Eloquent\Builder[]|\Illuminate\Database\Eloquent\Collection|Builder[]|Collection|mixed
         */
        public static function searchByRequestToPurchase(Request $request)
        {
            $search_item = self::getNotServiceItemToPurchase($request);
            if (count($search_item) == 0) {
                $search_item = self::getServiceItemToPurchase($request);

            }
            return $search_item;
        }
    }
