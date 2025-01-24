from django.shortcuts import render
from rest_framework.decorators import api_view
from rest_framework.response import Response
from django.http import JsonResponse

# Views
@api_view(['GET', 'POST'])
def receive_data(request):
    if request.method == 'POST':
        print("Received data:", request.data)
        return Response({"message": "Data received successfully"})
    print('get request')
    print(' data ', request.data)
      
    return Response({"message": "Send a POST request with data"})
