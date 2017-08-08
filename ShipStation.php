<?php

class ShipStation extends FulfillmentInterface
{
	function sendOrder($order)
	{
		extract((array) $order);
		
		$params = (object) array();
		$params->orderNumber = $clientOrderId;
		$params->orderKey = $this->key;
		$params->orderDate = $dateCreated;
		$params->paymentDate = $dateCreated;
		$params->orderStatus = 'awaiting_shipment';
		$params->customerUsername = $firstName.' '.$lastName;
		$params->customerEmail = $emailAddress;
		$params->billTo = (object) array();
		$params->billTo->name = $firstName.' '.$lastName;
		$params->billTo->company = $companyName;
		$params->billTo->street1 = $address1;
		$params->billTo->street2 = $address2;
		$params->billTo->street3 = NULL;
		$params->billTo->city = $city;
		$params->billTo->state = $state;
		$params->billTo->postalCode = $postalCode;
		$params->billTo->country = $country;
		$params->billTo->phone = $phoneNumber;
		$params->billTo->residential = NULL;
		$params->shipTo = (object) array();
		$params->shipTo->name = $firstName.' '.$lastName;
		$params->shipTo->company = $companyName;
		$params->shipTo->street1 = $shipAddress1;
		$params->shipTo->street2 = $shipAddress2;
		$params->shipTo->street3 = NULL;
		$params->shipTo->city = $shipCity;
		$params->shipTo->state = $shipState;
		$params->shipTo->postalCode = $shipPostalCode;
		$params->shipTo->country = $shipCountry;
		$params->shipTo->phone = $phoneNumber;
		
		$params->items = array();
		
		$totalWeight = 0;
		
		foreach($items as $item)
		{
			$args = new QueryArgs;
			$args->productName = $item->name;
			$args->productSku = $item->sku;
			$product = Product::fetch($args);	

			if(empty($product->weight))		
				$product->weight = '5';
			
			$params->items[] = (object) array(
												'lineItemKey' => $product->productId,
												'sku' => $item->sku,
												'name' => $item->name,
												'imageUrl' => NULL,
												'weight' => array('value' => $product->weight, 'units' => 'pounds'),
												'quantity' => $item->qty,
												'unitPrice' => $item->price,
												'warehouseLocation' => NULL,
												'options' => NULL,
												'adjustment' => false //Indicates that the OrderItem is a non-physical adjustment to the order 
												);
	
			
			$totalWeight += $product->weight;
		}
		
		$params->amountPaid = $totalAmount;
		$params->taxAmount =  $salesTax;
		$params->shippingAmount = $shippingPrice;
		$params->customerNotes = NULL;
		$params->internalNotes = NULL;
		$params->gift = false;
		$params->giftMessage = NULL;
		$params->requestedShippingService = NULL;
		$params->paymentMethod = NULL;
		$params->carrierCode = NULL;
		$params->serviceCode = NULL;
		$params->packageCode = NULL;
		$params->confirmation = NULL;
		$params->shipDate = NULL;
		$params->weight = (object) array();
		$params->weight->value = $totalWeight;
		$params->weight->units = 'pounds';
		$params->insuranceOptions = (object) array();
		$params->insuranceOptions->provider = NULL;
		$params->insuranceOptions->insureShipment = false;
		$params->insuranceOptions->insuredValue = 0;
		$params->internationalOptions = (object) array();
		$params->internationalOptions->contents = NULL;
		$params->internationalOptions->customsItems = NULL;
		$params->advancedOptions = (object) array();
		$params->advancedOptions->warehouseId = NULL;
		$params->advancedOptions->nonMachinable = false; // 	Specifies whether the order is non-machinable.
		$params->advancedOptions->saturdayDelivery = false; // Specifies whether the order is to be delivered on a Saturday.
		$params->advancedOptions->containsAlcohol = false;
		$params->advancedOptions->storeId = NULL;
		$params->advancedOptions->customField1 = NULL;
		$params->advancedOptions->customField2 = NULL;
		$params->advancedOptions->customField3 = NULL;
		$params->advancedOptions->source = NULL;
		
		$url = "https://ssapi.shipstation.com/orders/createorder";
		$headers = array("Content-Type: application/json");
		
		$request = new HttpRequest($url);
		$request->headers = $headers;
		$request->setUserPwd($this->key,$this->secret);
		$request->method = 'POST';
		$request->body = json_encode($params);			
		
		$response = HttpClient::sendRequest($request);
		$this->logRequest($request);
					
		$result = json_decode($response->body);

		if(empty($result->orderId))
		{
			if(empty($result->Message))
			{
				$errorStr = "Unknown Error: Please contact Customer Support";
			}
			else
			{
				$errorStr = $result->Message;
			}
			$this->setFulfillmentFailed($fulfillmentId,$errorStr);
		}
		else
		{
			$clientFulfillmentId = $result->orderId;
			$this->setFulfillmentSuccess($fulfillmentId,$clientFulfillmentId);	
		}
	}
	
	function updateTracking()
	{	
		$fhouseData = $this->fhouseData;
		$sql = "SELECT clientFulfillmentId FROM fulfillments WHERE status = 'PENDING' AND fulfillmentHouseId = ? AND dateCreated >= DATE_SUB(NOW(),INTERVAL 6 DAY)";
		$ids = $this->server->fetchValueList($sql,$fhouseData->fulfillmentHouseId);
				
		foreach($ids as $clientFulfillmentId)
		{
			$url = "https://ssapi.shipstation.com/shipments?orderId=$clientFulfillmentId&pageSize=1";
			
			$request = new HttpRequest($url);
			$request->setUserPwd($this->key,$this->secret);
			$request->method = 'GET';
			
			$response = HttpClient::sendRequest($request);
			$this->logRequest($request);
			
			$result = json_decode($response->body);
						
			if(is_object($result))
			{
				$sql = "SELECT fulfillmentId FROM fulfillments WHERE clientFulfillmentId = ? AND fulfillmentHouseId = ?";
				$fulfillmentId = $this->server->fetchValue($sql,$clientFulfillmentId,$this->fulfillmentHouseId);
				
				if(!empty($result->shipments[0]->trackingNumber))
				{
					$trackingNumber = $result->shipments[0]->trackingNumber;
					$shipCarrier = $result->shipments[0]->carrierCode.' '.$result->shipments[0]->serviceCode;
					
					$this->updateFulfillmentTracking($fulfillmentId,$trackingNumber,new DateTime,$shipCarrier);
				}
				elseif(!empty($result->shipments[0]->voided))
				{
					$sql = "UPDATE fulfillments SET status = 'CANCELLED' WHERE fulfillmentId = ?";
					$this->server->execute($sql,$fulfillmentId);
					
					$sql = "UPDATE fulfillment_items SET status = 'CANCELLED' WHERE fulfillmentId = ?";
					$this->server->execute($sql,$fulfillmentId);
				}
			}
			
		}
	}
	
}
