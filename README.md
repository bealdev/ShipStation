# ShipStation - Fulfillment Integration

ShipStation is a fulfillment house used to trigger to send products from orders via API.

## Getting Started

Authorization credentials provided by ShipStation is required in order to test the API. One must install ShipStation and add all necessary credentials.

### Prerequisites

The API can be achieved successfully via basic http request with POST method. $this->key and $this->secret can be exchanged with credentials provided by BrightSpeed.

```
https://ssapi.shipstation.com/orders/createorder?key=9jf89wfew8j&secret=fi8w9043fnj&...
```

## Running the tests

Confirmed if the ShipStation software behaved correctly via POST API request.

## Authors

* **Brian Beal** - *Initial work* - [bealdev](https://github.com/bealdev)
