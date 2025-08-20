# me - this DAT
# 
# channel - the Channel object which has changed
# sampleIndex - the index of the changed sample
# val - the numeric value of the changed sample
# prev - the previous sample value
# 
# Make sure the corresponding toggle is enabled in the CHOP Execute DAT.
import requests 
import winsound
import time
update_url = "http://127.0.0.1:3000/backend/data.php?action=get_update"
poll_url = "http://127.0.0.1:3000/backend/data.php?action=poll"
question_count = 5


def fetch_api_data(endpoint):
    """
    Fetches JSON data from a given API endpoint.

    Args:
        endpoint (str): The full URL of the API endpoint to call.

    Returns:
        dict or None: The parsed JSON data if the request is successful, 
                      otherwise None.
    """
    try:
        # Make the GET request to the API
        response = requests.get(endpoint)
        
        # Check for HTTP errors (e.g., 404, 500)
        response.raise_for_status()
        
        # Parse the JSON data from the response
        data = response.json()
        # print(f"Successfully fetched data from {endpoint}:")
        # print(data)
        return data
        
    except requests.exceptions.RequestException as e:
        # Catch any request-related errors (HTTP, connection, timeout)
        print(f"An error occurred while fetching data from {endpoint}: {e}")
    except ValueError:
        # Handle cases where the response is not valid JSON
        print(f"Error: Could not decode JSON from the response from {endpoint}.")
    
    return None

def onOffToOn(channel, sampleIndex, val, prev):
    return

def whileOn(channel, sampleIndex, val, prev):
    return

def onOnToOff(channel, sampleIndex, val, prev):
    return

def whileOff(channel, sampleIndex, val, prev):
    return

def onValueChange(channel, sampleIndex, val, prev):
    """
    This function is a callback that fetches data from an API and performs an action.
    """
    winsound.Beep(1500, 50)
    

    # Call the reusable function for each endpoint
    update_data = fetch_api_data(update_url)

    # You can now use the update_data variable here if the call was successful
    if update_data:
        # Example: process the data
        print(f"Successfully fetched data from {update_url[-10:]}:")
        print(update_data)
        if update_data.get('state') == 'waiting':
            op('waiting').par.value0  = 1
            op('playing').par.value0  = 0
            op('ending').par.value0  = 0
        if update_data.get('state') == 'playing':
            print(update_data['answers'][-1]['question_id'],'q_id')
            op('question_i').par.value0  = update_data['answers'][-1]['question_id']
            file_name = ''
            index = 0
            for i in update_data['answers']:
                index +=1
                file_name += str(i['answer'])
                print(i['answer'])
            file_name +=  'x'*(question_count-index)+'.png'
            print('photos/' +file_name)
            op('q'+str(index)).par.file = 'photos/' +file_name

            op('waiting').par.value0  = 0
            op('playing').par.value0  = 1
            op('ending').par.value0  = 0        
        if update_data.get('state') == 'ending':
            op('waiting').par.value0  = 0
            op('playing').par.value0  = 0
            op('ending').par.value0  = 1
        pass

    poll_data = fetch_api_data(poll_url)
    if poll_data:
        # Example: process the data
        print(f"Successfully fetched data from {poll_url[-10:]}:")
        print(poll_data)
        my_null_chop = op('new_ans_subed')
        
        if poll_data.get('flag') == 1:
            # change a chop value here
            winsound.Beep(2500, 50)

            my_null_chop.par.value0  = 1
        else:
            my_null_chop.par.value0  = 0

        pass
    return
