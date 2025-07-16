# NeuronWriter API – how to use - NEURONwriter - Content optimization with #semanticSEO

Source: https://neuronwriter.com/faqs/neuronwriter-api-how-to-use/
Jina URL: https://r.jina.ai/https://neuronwriter.com/faqs/neuronwriter-api-how-to-use/



NeuronWriter API gives you programatic access to our recommendations. With it you can for instance:

Add new queries in bulk and retrieve the share URLs
Integrate NeuronWriter recommendations
in your content generation process

(This is a beta version of our API. If you face any issues or have any thoughts on how we can improve our API, please add your feedback. Thank you!)

The quickest way to start might be to jump straight to the usage examples at the end of these docs.

Requirements / costs

This feature requires Gold plan or higher.
API requests consume your monthly limits (there is no difference whether you create a new query via NeuronWriter interface or API, the cost is the same)
It is advised to have a basic understanding of how APIs/HTTP requests work and a bit of experience in your favorite programming language (Python, PHP, Java, …)

How to obtain your API key?

Open your profile, visit the “Neuron API access” tab and copy your API key:

API access:
API endpoint:

Please use the following API endpoint:
https://app.neuronwriter.com/neuron-api/0.5/writer (+ API method)

Authentication:

Each request to the API has to include the X-API-KEY HTTP header with your Neuron API key.

API methods

The methods have been described below. Unless specified otherwise, each of these methods is requested via POST request.

/list-projects

Retrieves a list of projects within the used account.

Parameters:

None required

Response:

A list of projects:

[{"project": "ed0b47151fb35b02", "name": "adidas.com", "language": "English", "engine": "google.co.uk"}, {"project": "e6a3198027aa1b96", "name": "asics.com", "language": "English", "engine": "google.co.uk"}, {"project": "688b8de5e6774060", "name": "nike.com", "language": "English", "engine": "google.co.uk"}]
/new-query

Creates a new content writer query for a given keyword, language and search engine.

Parameters:
parameter	example value	description
project	e95fdd229fd98c10	The ID of your project taken from project’s URL: https://app.neuronwriter.com/project/view/75a454f6ae5976e8 -> e95fdd229fd98c10
keyword	trail running shoes	The keyword you want to generate a query (and recommendations) for.
engine	google.co.uk	Preferred search engine.
language	English	Content language.
Response:
key	example value	description
query	32dee2a89374a722	The ID of your new query.
query_url	https://app.neuronwriter.com/analysis/view/32dee2a89374a722	The URL of your query (accessible from browser)
share_url	https://app.neuronwriter.com/analysis/share/32dee2a89374a722/c63b64f6b13a064c12b78dac7dc3410c1	URL for sharing the query (with edit&save access)
readonly_url	https://app.neuronwriter.com/analysis/content-preview/32dee2a89374a722/02db6ba3e78557302723220bdef73c771	URL for sharing the query (readonly preview)
/get-query

Retrieves content recommendations for a given query. Any query, doesn’t have to be created via API.
After creating a new query with /new-query request it usually takes around 60 seconds until we prepare the recommendations.
With /get-query request you can check whether we’ve finished the analysis (status==’ready’) and if we did, the recommendations are available in the response.

Parameters:
parameter	example value	description
query	32dee2a89374a722	The ID of your query.
Response:
key	example value	description
status	ready	Whether your query has already been processed. Possible values include: not found, waiting, in progress, ready If status==’ready’, keys below are added too.
metrics	{‘word_count’: {‘median’: 1864, ‘target’: 1864}, ‘readability’: {‘median’: 40, ‘target’: 40}}	General recommendations such as typical content length.
terms_txt	{‘title’: ‘running shoenwinter running shoenbest winter running shoenwinter running shoes of 2024’, ‘desc_title’: ‘running shoenwinter running shoenbest winter running shoentractionnbest pairsnwinter running shoes of 2024n2024ntrail runningnshoes for runningnrunning in the winternfeet warmnwinter shoes’, ‘h1’: ‘running shoe…	Content term suggestions (title, desc, h1, h2, content_basic, content_extended) in text format. Good to be used for Chat GPT prompts, etc.
terms	{‘title’: [{‘t’: ‘running shoe’, ‘usage_pc’: 88}, {‘t’: ‘winter running shoe’, ‘usage_pc’: 75}, {‘t’: ‘best winter running shoe’, ‘usage_pc’: 62}, {‘t’: ‘winter running shoes of 2024’, ‘usage_pc’: 50}], ‘desc’: [{‘t’: ‘running shoe’, ‘usage_pc’: 25}, {‘t’: ‘winter running shoe’, ‘usage_pc’: 25}, {‘t’: ‘best winter running shoe’, ‘usage_pc’: 25}, … ‘content_basic’: [{‘t’: ‘running shoe’, ‘usage_pc’: 88, ‘sugg_usage’: [1, 32]}, {‘t’: ‘winter running shoe’, ‘usage_pc’: 88, ‘sugg_usage’: [1, 9]}, {‘t’: ‘best winter running shoe’, ‘usage_pc’: 75, ‘sugg_usage’: [1, 2]}, {‘t’: ‘winter running shoes of 2024’, ‘usage_pc’: 50, ‘sugg_usage’: [1, 1]}, {‘t’: ‘2024’, ‘usage_pc’: 75, ‘sugg_usage’: [1, 2]}, {‘t’: ‘traction’, ‘usage_pc’: 62, ‘sugg_usage’: [1, 16]}, {‘t’: ‘outsole’, ‘usage_pc’: 62, ‘sugg_usage’: [1, 19]}, …], ‘entities’: [{‘t’: ‘Trail running’, ‘importance’: 27.31264, ‘relevance’: 0.42676, ‘confidence’: 3.2872, ‘links’: [[‘wikipedia’, ”]]}, …]}	Detailed information about the content terms.
ideas	{‘suggest_questions’: [{‘q’: ‘where to buy trail running shoes’}, {‘q’: ‘are trail running shoes comfortable’}, …], ‘people_also_ask’: [{‘q’: ‘What is the difference between a running shoe and a trail running shoe?’}, {‘q’: ‘Can running shoes be used for trail?’}, …], ‘content_questions’: [{‘q’: ‘Can I use road running shoes for trail running?’}, {‘q’: ‘How many miles do trail running shoes last?’}, …]}	Questions related to the topic (suggest questions, People Also Ask, questions extracted from content)
competitors	[{‘rank’: 1, ‘url’: ‘https://www.runnersworld.com/gear/a22115120/best-trail-running-shoes/‘, ‘title’: ‘The 11 Best Trail Running Shoes of 2024 – Best Off-Road Running Shoes’, ‘desc’: ‘Check out the Runner’s World editors’ picks for the 11 best trail and off-road running shoes so far in 2024. ‘}, …],	Basic information about the SERP competitors.
/list-queries

Retrieves queries within a project matching your criteria and provides information about their status (whether they’re ready, etc.).

Parameters:
parameter	example value	description
project	0c30b6a4f8b2b412	The ID of your project.
status	ready	Query status – possible values: waiting, in progress, ready
source	neuron-api	How was the query created – possible values: neuron, neuron-api
created	2024-01-19T14:48:31+00:00	Creation date for a query
updated	2024-01-19T14:49:52+00:00	Query update (when the query is ready)
keyword	trail running shoes	Main keyword for the query
language	English	Language used during analysis.
engine	google.com	Search engine used during analysis.
tags	Done	You can limit the results to queries with one or more tags. You can either provide a single tag as a string, e.g.: Done or a list of tags such as (all have to be present): [‘MyNewTag’, ‘Done’,]
Response:

A list of queries:

[{'query': 'a6d0fb2bf9a7a2be', 'created': '2024-01-19T14:48:31+00:00', 'updated': '2024-01-19T14:49:52+00:00', 'keyword': 'how should running shoes fit', 'language': 'English', 'engine': 'google.co.uk', 'source': 'neuron-api', 'tags': ['Done']}, {'query': 'bdc73d8e0057ed8f', 'created': '2024-01-19T14:48:31+00:00', 'updated': '2024-01-19T14:49:52+00:00', 'keyword': 'trail running shoes for winter', 'language': 'English', 'engine': 'google.co.uk', 'source': 'neuron-api', 'tags': ['Done']}]
/get-content

Retrieves the last content revision saved for a given query. Any query, doesn’t have to be created via API.
You can choose between manually saved revisions (default) or all (including autosave revisions)

Parameters:
parameter	example value	description
query	32dee2a89374a722	The ID of your query.
revision_type	all	Whether autosave revisions should be considered or not
Response:
key	example value	description
content	

Top 10 Best Winter Running Shoes of 2024 – Find the Perfect Pair for Cold Weather Runs

Winter running shoes are designed to provide the necessary support, comfort, and protection for running in cold and icy conditions. The top winter running shoes of 2024 not only keep your feet warm and dry but also offer traction and stability on slippery surfaces….

	Content in HTML format
title	Top 10 Best Winter Running Shoes of 2024 – Find the Perfect Pair for Cold Weather Runs	Title
description	Discover the top 10 best winter running shoes of 2024 for staying warm and steady on your cold weather runs. Find the perfect pair with great traction!	Meta description
created	2024-01-19T14:49:52+00:00	Revision date
type	manual	Revision type (manual or autosave)
/import-content

Allows you to update the editor content via API for a given query. Any query, doesn’t have to be created via API.
You can either import the HTML or send the URL that we will try to auto-import and parse content from. One of these two has to be provided.

Parameters:

parameter

	

example value

	

description




query

	

32dee2a89374a722

	

The ID of your query.




html

	

<h1>Best Trail Running Shoes in 2024: A Complete Guide</h1><p>As a trail runner, choosing the right pair of trail running shoes…

	

HTML content to import




title

	

“Best Trail Running Shoes in 2024: A Complete Guide”

	

[optional] <title> of your article. If provided, overwrites the title found in HTML or imported via URL




description

	

“Discover the top trail running shoes of 2024, including models from Altra, Hoka, Nike, and more. Find your perfect pair with our complete guide.”

	

[optional] meta description of your article. If provided, overwrites the description found in HTML or imported via URL




url

	

https://runningshoesexpert.com/blog/trail-running-shoes

	

The URL we will try to auto-import and parse content from.




id

	

main-content

	

(optional) When providing the url to auto-import content from, you can specify the id of the container that contains the content in the HTML structure.




class

	

article-content

	

(optional) When providing the url to auto-import content from, you can specify the class of the container that contains the content in the HTML structure.

Response:

key

	

example value

	

description




status

	

ok

	

Import status

Additionally, error can be proivded if there was an issue during the import (e.g. you provided the URL we couldn’t import content from).

/evaluate-content

Everything is exactly the same as for /import-content, the input and output parameters are the same. The only difference is, /import-content creates a new content revision. /evaluate-content does not save anything, just evaluates the content score.

Usage example (Python 3 + requests library)
Creating a new query:
#coding=utf-8

import json
import requests

API_ENDPOINT = 'https://app.neuronwriter.com/neuron-api/0.5/writer'
API_KEY = 'n-c17ef87a9ab8285ea7ef06064031fad4'

headers = {
    "X-API-KEY": API_KEY,
    "Accept": "application/json",
    "Content-Type": "application/json",
}

### Creating a new query:
payload = json.dumps({
# Project ID can be found in the project's URL: https://app.neuronwriter.com/project/view/c2fe46bce8019bff/optimisation -> c2fe46bce8019bff
    "project": "c2fe46bce8019bff",
    "keyword": "trail running shoes",
    "engine": "google.co.uk",
    "language": "English",
})

response = requests.request("POST", API_ENDPOINT + "/new-query", headers=headers, data=payload)
print(response.text)

Output (JSON):

{"query": "79ca6b6b45fb9d67", "query_url": "https://app.neuronwriter.com/analysis/view/79ca6b6b45fb9d67", "share_url": "https://app.neuronwriter.com/analysis/share/79ca6b6b45fb9d67/cceca0f2e01e17e998e72eaa2d4030261562", "readonly_url": "https://app.neuronwriter.com/analysis/content-preview/79ca6b6b45fb9d67/96e0109dea412bb07690999de5cb67ce1562"}
Checking whether your query is ready / printing recommendations:

Use a query ID returned by the /new-query request.

#coding=utf-8

import json
import requests

API_ENDPOINT = 'https://app.neuronwriter.com/neuron-api/0.5/writer'
API_KEY = 'n-c17ef87a9ab8285ea7ef06064031fad4'

headers = {
    "X-API-KEY": API_KEY,
    "Accept": "application/json",
    "Content-Type": "application/json",
}

payload = json.dumps({
    "query": "79ca6b6b45fb9d67", # query ID returned by /new-query request
})

response = requests.request("POST", API_ENDPOINT + "/get-query", headers=headers, data=payload)
response_data = response.json()

if response_data['status'] == 'ready': # We've finished the analysis
    print('########### WORD COUNT: ###########')
    print(response_data['metrics']['word_count']['target'])
    print('########### TERMS: ###########')
    print('########### title terms: ###########')
    print(response_data['terms_txt']['title'])
    print('########### basic content terms: ###########')
    print(response_data['terms_txt']['content_basic'])
    print('########### basic content terms with use ranges: ###########')
    print(response_data['terms_txt']['content_basic_w_ranges'])
    print('########### entities: ###########')
    print(response_data['terms_txt']['entities'])

    print('########### SUGGEST QUESTIONS: ###########')
    for q in response_data['ideas']['suggest_questions']:
        print(q['q'])
        
    print('########### PAA QUESTIONS: ###########')
    for q in response_data['ideas']['people_also_ask']:
        print(q['q'])

    print('########### CONTENT QUESTIONS: ###########')
    for q in response_data['ideas']['content_questions']:
        print(q['q'])

    print('########### COMPETITORS: ###########')
    for comp in response_data['competitors']:
        print(comp['rank'], '|', comp['url'])
        print('\tTITLE:', comp['title'])
        print('\tCONTENT SCORE:', comp['content_score'])
else:
    pass # Try again later...

Printed output:

########### WORD COUNT: ###########
1498
########### TERMS: ###########
########### title terms: ###########
trail running shoe
best trail running shoe
2024
trail running shoes of 2024
salomon
brook
########### basic content terms: ###########
trail running shoe
best trail running shoe
trail shoes
trail runner
salomon
terrain
nike
traction
lug
cushion
altra
road shoe
2024
brook
nike pegasus trail
road running
upper
saucony peregrine
outsole
rugged
midsole
underfoot
toe box
off-road
grippy
energy return
technical trails
running gear
tread
racer
rugged terrain
snug fit
########### basic content terms with use ranges: ###########
trail running shoe: 6-21x
best trail running shoe: 1-2x
trail shoes: 1-7x
trail runner: 1-3x
salomon: 1-5x
terrain: 1-12x
nike: 1-12x
traction: 1-8x
lug: 1-14x
cushion: 1-11x
altra: 1-6x
road shoe: 1-3x
2024: 1-6x
brook: 1-4x
nike pegasus trail: 1-4x
road running: 1x
upper: 1-14x
saucony peregrine: 1-4x
outsole: 1-10x
rugged: 1-3x
midsole: 1-6x
underfoot: 1-4x
toe box: 1x
off-road: 1x
grippy: 1-3x
energy return: 1-3x
technical trails: 1-2x
running gear: 1x
tread: 1x
racer: 1-2x
rugged terrain: 1x
snug fit: 1x
########### entities: ###########
Trail running
Trail
Shoe
Sneakers
Running
Toe box
Foot
Hiking
Nike, Inc.
Natural rubber
Saucony
Energy
Off-roading
Accessibility
Road
Hoka One One
Wear
Vibram
Debris
Foam
Terrain
Ultramarathon
Experience
Textile
Traction (mechanics)
Mountain
Toe
Gear
Ankle
Tongue
Forward (association football)
Lace
Speedgoat
########### SUGGEST QUESTIONS: ###########
where to buy trail running shoes
are trail running shoes comfortable
can trail running shoes be used on the road
can trail running shoes be used for cross training
when to buy trail running shoes
how to lace trail running shoes
...
########### PAA QUESTIONS: ###########
What is the difference between a running shoe and a trail running shoe?
Can running shoes be used for trail?
Are trail running shoes OK for street?
Can you wear trail runners every day?
Can I use normal running shoes on trail?
...
########### CONTENT QUESTIONS: ###########
Can I use road running shoes for trail running?
How many miles do trail running shoes last?
How much cushioning do you need in trail shoes?
How soft do you want your trail shoes?
...
########### COMPETITORS: ###########
1 | https://www.runnersworld.com/gear/a22115120/best-trail-running-shoes/
        TITLE: The 11 Best Trail Running Shoes of 2024 - Best Off-Road Running Shoes
        CONTENT SCORE: 89
2 | https://www.hoka.com/en/us/womens-trail/
        TITLE:
        CONTENT SCORE:
3 | https://www.salomon.com/en-us/shop/sports/trail-running/shoes.html
        TITLE: Trail Running Shoes - Salomon
        CONTENT SCORE: 24
4 | https://www.outdoorgearlab.com/topics/shoes-and-boots/best-trail-running-shoes
        TITLE: The 13 Best Trail Running Shoes of 2024 | Tested
        CONTENT SCORE: 92
...
Getting a list of queries within a project, matching your criteria:
#coding=utf-8

import json
import requests

API_ENDPOINT = 'https://app.neuronwriter.com/neuron-api/0.5/writer'
API_KEY = 'n-c17ef87a9ab8285ea7ef06064031fad4'

headers = {
    "X-API-KEY": API_KEY,
    "Accept": "application/json",
    "Content-Type": "application/json",
}

payload = json.dumps({
    "project": "38319e9e7eb7848f",
    "status": 'ready',
    "source": 'neuron-api',
    "tags": ['MyNewTag', 'Done',]
})

response = requests.request("POST", API_ENDPOINT + "/list-queries", headers=headers, data=payload)
response_json = response.json()
print(response_json)

Printed output:

[{'id': '5ecdd5d09a461f58', 'query': '5ecdd5d09a461f58', 'created': '2024-01-19T14:49:52+00:00', 'updated': '2024-01-19T14:49:52+00:00', 'source': 'neuron-api', 'tags': ['Done', 'MyNewTag']}]
Retrieving the last content revision saved for a given query.
#coding=utf-8

import json
import requests

API_ENDPOINT = 'https://app.neuronwriter.com/neuron-api/0.5/writer'
API_KEY = 'n-c17ef87a9ab8285ea7ef06064031fad4'

headers = {
    "X-API-KEY": API_KEY,
    "Accept": "application/json",
    "Content-Type": "application/json",
}

payload = json.dumps({
    "query": "79ca6b6b45fb9d67",
})

response = requests.request("POST", API_ENDPOINT + "/get-content", headers=headers, data=payload)
response_json = response.json()
print(response_json)
print('########### title: ###########')
print(response_json['title'])
print('########### description: ###########')
print(response_json['description'])
print('########### HTML content: ###########')
print(response_json['content'])

Printed output:

########### title: ###########
Top 10 Best Winter Running Shoes of 2024 - Find the Perfect Pair for Cold Weather Runs
########### description: ###########
Discover the top 10 best winter running shoes of 2024 for staying warm and steady on your cold weather runs. Find the perfect pair with great traction!
########### HTML content: ###########
<h1>Top 10 Best Winter Running Shoes of 2024 - Find the Perfect Pair for Cold Weather Runs</h1>
<p>Winter running shoes are designed to provide the necessary support, comfort, and protection for running in cold and icy conditions. The top winter running shoes of 2024 not only keep your feet warm and dry but also offer traction and stability on slippery surfaces. These shoes typically feature waterproof membranes, durable outsoles with lugs for enhanced grip, and insulated uppers to shield your feet from the chilly weather.</p>
...
Updating the editor content via API (raw HTML + title and description)
#coding=utf-8

import json
import requests

API_ENDPOINT = 'https://app.neuronwriter.com/neuron-api/0.5/writer'
API_KEY = 'c17ef87a9ab8285ea7ef06064031fad4'

headers = {
    "X-API-KEY": API_KEY,
    "Accept": "application/json",
    "Content-Type": "application/json",
}

payload = json.dumps({
        "query": "f67d0dac14a35a86",
        "html": '''<h1>Best Trail Running Shoes in 2024: A Complete Guide</h1>
<p>As a trail runner, choosing the right pair of trail running shoes is crucial for a successful and enjoyable running experience. With the wide range of trail running shoes available in 2024, it can be overwhelming to find the best shoe that suits your running style and preferences. In this guide, we will explore what makes a great trail running shoe, discuss the top trail running shoe brands, compare the best trail running shoes of 2024, delve into the technology behind trail-running shoes, and help you choose the best overall trail running shoe.</p>
<h2>What makes a great trail running shoe?</h2>
<p>When looking for the perfect trail running shoe, there are several key features to consider that can significantly impact your performance on the trails. A good trail running shoe should provide adequate support, cushioning, and protection while being durable enough to withstand the rigors of varied terrains.</p>
<h3>Key features to look for in trail running shoes</h3>
<p>Key features to look for in a trail running shoe include a durable outsole with lugs for traction, a protective rock plate to shield your feet from sharp objects, a roomy and protective toe box, and a comfortable and supportive midsole for underfoot cushioning.</p>
<h3>Importance of traction in trail running shoes</h3>
<p>The traction of a trail running shoe is essential for maintaining grip on varied terrains such as technical trails, muddy paths, and rocky surfaces. Lugs on the outsole provide the necessary traction to help you navigate challenging terrain and prevent slips and falls.</p>''',
        "title": "Best Trail Running Shoes in 2024: A Complete Guide",
        "description": "Discover the top trail running shoes of 2024, including models from Altra, Hoka, Nike, and more. Find your perfect pair with our complete guide.",
    })

response = requests.request("POST", API_ENDPOINT + "/import-content", headers=headers, data=payload)
response_json = response.json()
print(response_json)

Printed output:

{"status": "ok", "content_score": 25}

