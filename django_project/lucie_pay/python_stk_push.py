
import requests


client_id = 'gGWQm4hFEn5iqI_9pz_-6ki5yfZY_G7aSecF0bUMiH8'
client_secret = 'opQW-Q7duzJ3FnrV7d7hM21o8ixEViM-dX21q9AX5E4'

url = f"https://api.kopokopo.com/oauth/token?grant_type=client_credentials&client_id={client_id}&client_secret={client_secret}"

payload = {}
headers = {
  'Accept': 'application/json'
}

response = requests.request("POST", url, headers=headers, data=payload)

print(response.text)
print('access token ',response.json()['access_token'])


bearer = response.json()['access_token']

import json

url = "https://api.kopokopo.com/api/v1/incoming_payments"

payload = json.dumps({
  "payment_channel": "M-PESA STK Push",
  "till_number": "K856735",
  "subscriber": {
    "first_name": "Yonah",
    "last_name": "Owiti",
    "phone_number": "0795680221",
    "email": "watiapi@gmail.com"
  },
  "amount": {
    "currency": "KES",
    "value": 1
  },
  "metadata": {
    "customer_id": "123456789",
    "reference": "123456",
    "notes": "Payment for invoice 12345"
  },
  "_links": {
    "callback_url": "https://kopokopotest.oldonyo.com/wp-json/kopokopo/v1/callback"
  }
})
headers = {
  'Authorization': f'Bearer {bearer}',
  'Accept': 'application/json',
  'Content-Type': 'application/json'
}

response = requests.request("POST", url, headers=headers, data=payload)

print(response.text)
