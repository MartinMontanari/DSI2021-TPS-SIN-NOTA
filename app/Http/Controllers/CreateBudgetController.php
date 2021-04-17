<?php

namespace App\Http\Controllers;

use App\Http\Exceptions\UnprocessableEntityException;
use App\Models\Bag;
use App\Models\Budget;
use App\Models\BuildingMaterial;
use App\Models\Customer;
use http\Exception\RuntimeException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class CreateBudgetController
{
    private const BASE_COVERED_AREA_BY_DEFAULT_IN_SQUARE_METERS = 4.5;
    private const BASE_THICKNESS_LAYER_BY_DEFAULT_IN_MILLIMETERS = 100;

    public function __invoke(Request $request)
    {

        try {
            $this->requestAdapter($request);
            $customerId = $request->input('customerId');
            $layerThickness = $request->input('layerThickness');
            $insulatingMaterialId = $request->input('insulatingMaterialId');
            $areaToCover = $request->input('areaToCover');
        } catch (UnprocessableEntityException $error) {
            return redirect()->back()->withErrors($error->getMessage());
        }

        try {
            $customer = $this->findCustomerByIdOrFail($customerId);
            $buildingMaterialBag = $this->findBagByBuildingMaterialIdOrFail($insulatingMaterialId);
            $createdBudget = $this->createBudget($customer, $layerThickness, $buildingMaterialBag, $areaToCover);

            return view('budget.index',['createdBudget' => $createdBudget]);

        } catch (RuntimeException $error) {
            return redirect()->back()->withErrors($error->getMessage());
        }
    }

    /**
     * Function to adapt request parameters to handle use case
     * @param Request $request
     * @throws UnprocessableEntityException
     */
    private function requestAdapter(Request $request)
    {
        $validate = Validator::make($request->all(), self::RULES, self::MESSAGES);

        if ($validate->fails()) {
            throw new UnprocessableEntityException($validate->errors()->getMessages(),422);
        }
    }

    private const RULES = [
        'customerId' => 'bail|required|min:1',
        'insulatingMaterialId' => 'bail|required|min:1',
        'layerThickness' => 'bail|required|numeric|min:50|max:200',
        'areaToCover' => 'bail|required|numeric|min:4.5'
    ];

    private const MESSAGES = [
        'customerId.required' => 'Debe ingresar el cliente.',
        'customerId.min' => 'El cliente ingresado no es correcto.',
        'insulatingMaterialId.required' => 'Debe ingresar el material aislante.',
        'insulatingMaterialId.min' => 'El material aislante ingresado no es correcto.',
        'layerThickness.required' => 'Debe ingresar el espesor de la capa a aplicar.',
        'layerThickness.numeric' => 'El espesor de la capa a aplicar debe ser un número.',
        'layerThickness.min' => 'Debe ingresar una capa de 50mm como mínimo.',
        'layerThickness.max' => 'Debe ingresar una capa de 200mm como máximo.',
        'areaToCover.required' => 'Debe ingresar el área a cubrir en metros cuadrados.',
        'areaToCover.numeric' => 'El área a cubrir ingresada es incorrecta.',
        'areaToCover.min' => 'El área a cubrir ingresada es incorrecta, la superficie debe ser de 4,5 metros cuadrados como mínimo.'
    ];

    /**
     * Query to find a Customer by Id
     * @param int $customerId
     * @return Customer|null
     */
    private function findCustomerByIdOrFail(int $customerId): ?Customer
    {
        $query = Customer::query()->where('id', '=', $customerId)->get()->first();

        if (!isset($query)) {
            throw new \RuntimeException('El cliente no existe.', 404);
        }

        return $query;
    }

    /**
     * Query to find a Bag with a building material like parameter
     * @param int $materialId
     * @return Bag|null
     */
    private function findBagByBuildingMaterialIdOrFail(int $materialId): ?Bag
    {
        $query = Bag::query()->where('building_material_id', '=', $materialId)->get()->first();

        if (!isset($query)) {
            throw new RuntimeException('La bolsa de aislante no existe.', 404);
        }

        return $query;
    }

    /**
     * Function to create a Budget object, handle use case and persist the budget created.
     * @param Customer $customer
     * @param int $layerThickness
     * @param Bag $buildingMaterialBag
     * @param float $areaToCover
     * @return Budget
     */
    private function createBudget(Customer $customer, int $layerThickness, Bag $buildingMaterialBag, float $areaToCover): Budget
    {
        $budget = new Budget();

        $budget->setCustomer($customer);
        $budget->setLayerThickness($layerThickness);
        $budget->setBag($buildingMaterialBag);

        $budgetPrice = $this->makeBudgetPriceCalculation($layerThickness, $buildingMaterialBag->getBuildingMaterial(), $areaToCover);
        $budget->setPrice($budgetPrice);

        $budgetBagsQuantity = $this->makeBudgetBagsQuantityCalculation($areaToCover, $layerThickness);
        $budget->setTotallyBagsQuantity($budgetBagsQuantity);

        $budget->setExpirationDate(now() + 30);

        $budget->save();

        return $budget;
    }

    /**
     * Function to calculate the budget price
     * @param int $layerThickness
     * @param BuildingMaterial $buildingMaterial
     * @param float $areaToCover
     * @return float
     */
    private function makeBudgetPriceCalculation(int $layerThickness, BuildingMaterial $buildingMaterial, float $areaToCover): float
    {
        $insulatingMaterialCost = $buildingMaterial->getUnitPriceByCoverLayerThickness($layerThickness);

        return $areaToCover * $insulatingMaterialCost;
    }

    /**
     * Function to calculate how many bags the customer needs to cover the surface
     * @param float $areaToCover
     * @param int $layerThickness
     * @return float
     */
    private function makeBudgetBagsQuantityCalculation(float $areaToCover, int $layerThickness): float
    {
        if ($layerThickness === 100) {
            return $areaToCover / self::BASE_COVERED_AREA_BY_DEFAULT_IN_SQUARE_METERS;
        }
        return ($areaToCover * $layerThickness) / (self::BASE_COVERED_AREA_BY_DEFAULT_IN_SQUARE_METERS * self::BASE_THICKNESS_LAYER_BY_DEFAULT_IN_MILLIMETERS);
    }
}
