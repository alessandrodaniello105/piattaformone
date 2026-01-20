# Subscriptions: The webhooks are a pattern to deliver notifications between applications via HTTP endpoints, allowing external applications to register an HTTP endpoint to which notifications are delivered.

This means that the first step to being able to use this functionality is to Subscribe to the Service. On this page the Subscription process is explained, guiding you on requesting notifications for Fatture in Cloud events.
Start Using Webhooks Today!
Webhooks are now Generally Available — no prior authorization is required to use them. You can create subscriptions and start integrating webhooks into your application right away.

We've worked to ensure the system is stable and well-documented, but if you experience any issues or have suggestions, we welcome your feedback to help us further improve the feature.
☁️  CloudEvents
Our implementation is loosely compliant with the CloudEvents Specification.

You don't need to know the specification in detail to use our webhooks, but if you're curious you can check the dedicated page.
🎯  Prepare the Target
Our notifications must be sent to your system, so you should tell us where we have to send the messages; to do it, you'll need to prepare a Target and specify its address while creating a new subscription.
Start from the target!
We suggest you prepare the target beforehand, and only then create the subscription. This is because the subscription process includes a Verification Phase, and this requires the Target to send a proper response.
The Target is a simple HTTP endpoint, that must be exposed by your application and must be able to accept REST calls; specifically, it must be designed in a way that makes it able to accept GET and POST requests.

As you can imagine, our Webhooks will handle a certain number of Notification Types, that can be categorized as follows:
Verification Notifications
Welcome Notifications
Event Notifications
Where the third one is a set of many notification types.

In this phase, to complete the Subscription it is enough to be able to manage correctly the Verification Notifications; the correct way to answer to the Verification Notification will be explained below.

For now, it is enough to answer with a 200 OK to the other Notification Events; the proper way to manage this kind of request will be explained on the Notifications page.
Use HTTPS!
We expect you to expose your target using HTTPS, so we will refuse HTTP URLs when trying to create a Subscription.
In the next paragraphs we'll suppose that our target will be exposed at the following endpoint:
https://example.com/notifications

🗒  Subscribe
First, we need to require a new Subscription. To do so, we need to call the Create Subscription method like follows:
```
curl --location --request POST 'https://api-v2.fattureincloud.it/c/company_id/subscriptions' \
--header 'Authorization: Bearer ACCESS_TOKEN' \
--header 'Content-Type: application/json' \
--data-raw '{
   "data": {
       "sink": "https://example.com/notifications",
       "types": [
          "it.fattureincloud.webhooks.entities.all.delete",
          "it.fattureincloud.webhooks.issued_documents.all.update",
          "it.fattureincloud.webhooks.received_documents.create"
        ],
        "verification_method": "header",
        "config": {
          "mapping": "binary"
        }
    }
}
'
```

```
POST /c/company_id/subscriptions HTTP/1.1
Host: api-v2.fattureincloud.it
Authorization: Bearer ACCESS_TOKEN
Content-Type: application/json
Content-Length: 269

{
   "data": {
       "sink": "https://example.com/notifications",
       "types": [
          "it.fattureincloud.webhooks.entities.all.delete",
          "it.fattureincloud.webhooks.issued_documents.all.update",
          "it.fattureincloud.webhooks.received_documents.create"
       ],
       "verification_method": "header",
       "config": {
          "mapping": "binary"
       }
   }
}
```

```
<?php
require_once(__DIR__ . '/vendor/autoload.php');


// Configure OAuth2 access token for authorization: OAuth2AuthenticationCodeFlow
$config = FattureInCloud\Configuration::getDefaultConfiguration()->setAccessToken('YOUR_ACCESS_TOKEN');


$apiInstance = new FattureInCloud\Api\WebhooksApi(
    // If you want use custom http client, pass your client which implements `GuzzleHttp\ClientInterface`.
    // This is optional, `GuzzleHttp\Client` will be used as default.
    new GuzzleHttp\Client(),
    $config
);
$company_id = 12345; // int | The ID of the company.
$create_webhooks_subscription_request = new \FattureInCloud\Model\CreateWebhooksSubscriptionRequest; // \FattureInCloud\Model\CreateWebhooksSubscriptionRequest |

try {
    $result = $apiInstance->createWebhooksSubscription($company_id, $create_webhooks_subscription_request);
    print_r($result);
} catch (Exception $e) {
    echo 'Exception when calling WebhooksApi->createWebhooksSubscription: ', $e->getMessage(), PHP_EOL;
}
```

In the example, it is important to replace the Company ID with the ID of the Company owner of the resources we want to follow.
For example, if I created a Fatture in Cloud App using my company 12345, and I want to be notified about the resources of the company 67890, the Company ID will be 67890 and not my own.

The body will contain the following params:

Parameter	Description	Accepted Values	Default
data.sink	The HTTPS URL where the target is exposed		
data.types	An array of the event types the subscriber is interested in receiving		
data.verification_method	The verification method that will be used to activate the subscription, you can learn about the differences here	header, query	header
data.config.mapping	The structure of the event you will receive, you can learn about the differences here	binary, structured	binary
Are you authorized?
The request will be accepted only if the Access Token used for the request is associated with the company for the scopes related to the types inserted in the request.
The response provided by the Fatture in Cloud API will look something similar to this:

```
{
  "data": {
    "id": "SUB230",
    "sink": "https://example.com/notifications",
    "verified": true,
    "types": [
      "it.fattureincloud.webhooks.entities.suppliers.delete",
      "it.fattureincloud.webhooks.issued_documents.invoices.update",
      "it.fattureincloud.webhooks.issued_documents.quotes.update",
      "it.fattureincloud.webhooks.issued_documents.proformas.update",
      "it.fattureincloud.webhooks.issued_documents.receipts.update",
      "it.fattureincloud.webhooks.issued_documents.delivery_notes.update",
      "it.fattureincloud.webhooks.issued_documents.credit_notes.update",
      "it.fattureincloud.webhooks.issued_documents.orders.update",
      "it.fattureincloud.webhooks.issued_documents.work_reports.update",
      "it.fattureincloud.webhooks.issued_documents.supplier_orders.update",
      "it.fattureincloud.webhooks.issued_documents.self_invoices.update",
      "it.fattureincloud.webhooks.received_documents.create"
    ],
    "config": {
      "mapping": "binary"
    }
  },
  "warnings": [
    "The 'it.fattureincloud.webhooks.entities.clients.delete' event is already registered for this application"
  ]
}
```

There are two additional fields:
data.id: The ID of the subscription, that must be used for the other methods of the Subscriptions API; the ID will be a string, where the first part is the prefix "SUB" and the second part is an incremental number
warnings: This indicates that the creation of the subscription failed for some of the message types
You can have warnings in these cases:
If an app requested a message type for a company, and it already exists another subscription linked to the same message type for the same company. Only one subscription can be created for the couple app-type, so a duplicate will be rejected
If the type does not exist
The subscription will still be created for all the valid message types left.

Please, note that the message types are different from the request: the message types list includes some Group Types, that can be used to select all the related events with one single entry; the final subscription will still contain the actual types, because the group types will be exploded at creation time. Check the event types page to find out more about the group types.

The newly created Subscription will be Unverified until when the Verification step will succeed; this means that the target will not receive the expected events until when the Subscription will be verified.
Are you getting this error?
Your access to Webhooks has been temporarily disabled due to the detection of unusual behavior in your integration. If you're receiving an error when trying to create a subscription, this is likely the reason.

Please contact our support team to understand the issue and receive guidance on how to restore access.
{
  "error": {
    "message": "This app is not enabled to register webhooks",
    "code": "NO_PERMISSION"
  }
}

✅  Verify the subscription
Once the Subscription has been created, a new Verification Notification will be delivered to the Target to perform the Verification step: the Verification Notification will be sent as a REST GET call, and the target must be able to respond adequately.

This verification step is extremely important for Abuse Protection because it makes it possible for our systems to verify that your endpoint is expecting our notifications and that your endpoint was not registered by some malicious actor.

The verification step consists of retrieving a certain value, generated randomly and attached to the notification, and using it to generate a proper response as explained below. We currently provide two different Verification Methods, that differ only for the location of this random value:
In the Header Mode, the value will be added to the notification as the x-fic-verification-challenge header
In the Query Mode, the value will be appended to the URL as query string, in the x-fic-verification-challenge param
The Header Mode is our default, so it will be used when the request doesn't contain the data.verification_method param.

Below you can find an example of the Verification Notification, sent by our system to your endpoint:

```curl --location --request GET 'https://example.com/notifications' \
--header 'User-Agent: FattureInCloud/API-WEBHOOK' \
--header 'x-fic-verification-challenge: 292ff90a85ae68be5be1b2808a56cd183c3e8f72373b6cdda8e9dfd8e08f0f05' \
--header 'Authorization: Bearer SIGNATURE'
```
```
GET /notifications HTTP/1.1
Host: example.com
User-Agent: FattureInCloud/API-WEBHOOK
x-fic-verification-challenge: 292ff90a85ae68be5be1b2808a56cd183c3e8f72373b6cdda8e9dfd8e08f0f05
Authorization: Bearer SIGNATURE
```
```
curl --location --request GET 'https://example.com/notifications?x-fic-verification-challenge=292ff90a85ae68be5be1b2808a56cd183c3e8f72373b6cdda8e9dfd8e08f0f05' \
--header 'User-Agent: FattureInCloud/API-WEBHOOK' \
--header 'Authorization: Bearer SIGNATURE'
```
```
GET /notifications?x-fic-verification-challenge=292ff90a85ae68be5be1b2808a56cd183c3e8f72373b6cdda8e9dfd8e08f0f05 HTTP/1.1
Host: example.com
User-Agent: FattureInCloud/API-WEBHOOK
Authorization: Bearer SIGNATURE
```

The expected response is exactly the same for both the Verfication Methods. To be able to validate the request, the Target must be able to get the x-fic-verification-challenge value from the header or querystring parameter and insert it in the JSON response body as follows:
```
{
  "verification": "292ff90a85ae68be5be1b2808a56cd183c3e8f72373b6cdda8e9dfd8e08f0f05"
}
```

If Fatture in Cloud receives a 200 OK answer with this value included in the body, the subscription will be considered valid and Fatture in Cloud will start sending the required events to the Target.

Similarly to the other Notification types, the Verification Notification also includes the Authorization header, which can be used to verify if the request was generated by our systems. Please, check the dedicated section to understand how to verify this header.

If, while developing the code to manage the Verification Method, you weren't able to verify the subscription on the first attempt, you still have the possibility to verify your subscription. Check the dedicated section for a detailed description of the Verify method.
🎉  Welcome!
Once the verification step is complete, the messages will start being sent to your application. Since the first message could arrive in a few seconds or even days after the verification (it depends on when the first event is performed on the resources owned by the company), we decided to send a special Welcome Event to your endpoint when the verification step is concluded.

The Welcome Event will be similar to the other Events, but it will be recognizable by its special type it.fattureincloud.webhooks.subscriptions.welcome. Since it is just meant to give you a confirmation about the verification step, you can just ignore and discard this message or you could use it for your purposes: it's up to you.
It could be shy!
The Welcome event will be sent as one of the first messages for that subscription, but we cannot assure you that it will be the first message in every case.For example, if an event was generated during the validation procedure, it may be sent to the target before the Welcome Event, so we suggest using it just to confirm that the subscription was established correctly.
Check the Notifications page to find an example of a Welcome event.
🧮  List the subscriptions
If you want to check your active subscriptions on a certain company, you can use the List Subscriptions method. Below you can find an example:
```
curl --location --request GET 'https://api-v2.fattureincloud.it/c/company_id/subscriptions' \
--header 'Authorization: Bearer ACCESS_TOKEN'
```
```
GET /c/company_id/subscriptions HTTP/1.1
Host: api-v2.fattureincloud.it
Authorization: Bearer ACCESS_TOKEN
```

The Response Body will be similar to this one:
```
{
  "data": [
    {
      "id": "SUB155",
      "sink": "https://example.com/notifications",
      "verified": true,
      "types": ["it.fattureincloud.webhooks.entities.clients.delete"],
      "config": {
        "mapping": "binary"
      }
    },
    {
      "id": "SUB230",
      "sink": "https://example.com/webhooks",
      "verified": true,
      "types": [
        "it.fattureincloud.webhooks.entities.suppliers.delete",
        "it.fattureincloud.webhooks.issued_documents.invoices.update",
        "it.fattureincloud.webhooks.issued_documents.quotes.update",
        "it.fattureincloud.webhooks.issued_documents.proformas.update",
        "it.fattureincloud.webhooks.issued_documents.receipts.update",
        "it.fattureincloud.webhooks.issued_documents.delivery_notes.update",
        "it.fattureincloud.webhooks.issued_documents.credit_notes.update",
        "it.fattureincloud.webhooks.issued_documents.orders.update",
        "it.fattureincloud.webhooks.issued_documents.work_reports.update",
        "it.fattureincloud.webhooks.issued_documents.supplier_orders.update",
        "it.fattureincloud.webhooks.issued_documents.self_invoices.update",
        "it.fattureincloud.webhooks.received_documents.create"
      ],
      "config": {
        "mapping": "binary"
      }
    }
  ]
}
```

🍒  Get a subscription
If you know the Subscription ID, you can use the Get Subscription method to retrieve its current status. Below you can find an example:
```
curl --location --request GET 'https://api-v2.fattureincloud.it/c/company_id/subscriptions/SUB155' \
--header 'Authorization: Bearer ACCESS_TOKEN'
```
```
GET /c/company_id/subscriptions/SUB155 HTTP/1.1
Host: api-v2.fattureincloud.it
Authorization: Bearer ACCESS_TOKEN
```
And the Response Body will be similar to this:
```
{
  "data": {
    "id": "SUB155",
    "sink": "https://www.test.com",
    "verified": true,
    "types": ["it.fattureincloud.webhooks.entities.clients.delete"],
    "config": {
      "mapping": "binary"
    }
  }
}
```
📝  Modify the subscription
If you want, you can modify an existing Subscription by using the Modify Subscription method. Below you can find an example:
```
curl --location --request PUT 'https://api-v2.fattureincloud.it/c/company_id/subscriptions/SUB155' \
--header 'Authorization: Bearer ACCESS_TOKEN' \
--header 'Content-Type: application/json' \
--data-raw '{
  "data": {
    "types": [
        "it.fattureincloud.webhooks.entities.suppliers.delete"
    ]
  }
}
'
```
```
PUT /c/company_id/subscriptions/SUB155 HTTP/1.1
Host: api-v2.fattureincloud.it
Authorization: Bearer ACCESS_TOKEN
Content-Type: application/json
Content-Length: 190

{
  "data": {
    "types": [
      "it.fattureincloud.webhooks.entities.suppliers.delete"
    ]
  }
}
```
The Response Body will look something like this:
```
{
  "data": {
    "id": "SUB255",
    "sink": "https://www.test.com",
    "verified": true,
    "types": ["it.fattureincloud.webhooks.entities.suppliers.delete"],
    "config": {
      "mapping": "binary"
    }
  }
}
```
The main differences between this request and the Create request are the following:
The HTTP verb in this case is PUT instead of POST
You need to specify the Subscription ID in the URL since the subscription already exists
Are you relocating?
In most of the cases, after modifying a subscription you don't need to perform the Verification step a second time, because the subscription was already verified. An exception is the modification of the sink parameter: in this case, the subscription is invalidated, and you will receive a new Verification Event to the new Target endpoint.
🗑  Delete the subscription
If you don't need the subscription anymore, there are two main methods to remove a Subscription:
Delete it explicitly
Make your target return a 410 Gone error to an Event Notification
In both cases, some notifications could still be sent after the deletion of the subscription, but they should stop in a few minutes. There is another method to stop receiving notifications, but it is a safeguard mechanism that we use to avoid sending messages to dismissed or faulty systems. Check this page for further info about Expirations.
💣  Explicit deletion
In this case, the user needs to perform a Delete Subscription call. The request is something similar to:
```
curl --location --request DELETE 'https://api-v2.fattureincloud.it/c/company_id/subscriptions/SUB155' \
--header 'Authorization: Bearer ACCESS_TOKEN'
```
```
DELETE /c/company_id/subscriptions/SUB155 HTTP/1.1
Host: api-v2.fattureincloud.it
Authorization: Bearer ACCESS_TOKEN
```

🚽  Gone Response
In this case, the Target will return a 410 Gone status to the next Notification Event sent by the webhooks. When the Webhooks service receives a 410 Gone on a notification request, it considers the target as dismissed and it immediately deletes the related subscription. Of course, in this case the subscription could stay active for a certain amount of time, until when the first event will be sent to the target.
🚂  Did you miss it?
A failed verification doesn't necessarily mean that your subscription is lost forever.

Of course, if you weren't able to verify the subscription when you received the Verification Notification, you won't be able to receive the other kinds of notifications you were expecting. Feel free to delete the subscription and create a new one, we promise we won't get offended. 🤗

At the same time, we know that at development time it would be frustrating to delete and recreate a subscription multiple times, so we provided you a method to require a new verification attempt.

The request you must perform is similar to:
```
curl --location --request POST 'https://api-v2.fattureincloud.it/c/company_id/subscriptions/SUB155/verify' \
--header 'Authorization: Bearer ACCESS_TOKEN' \
--header 'Content-Type: application/json' \
--data-raw '{
  "data": {
    "verification_method": "header"
  }
}
'
```
```
PUT /c/company_id/subscriptions/SUB155/verify HTTP/1.1
Host: api-v2.fattureincloud.it
Authorization: Bearer ACCESS_TOKEN
Content-Type: application/json
Content-Length: 190

{
  "data": {
    "verification_method": "header"
  }
}
```
The only (optional) parameter contained in the body is the data.verification_method param, that works exactly as explained in the Subscribe section. Please, note that we don't store this value, so you'll need to send it at every retry if you don't plan to use the default method (e.g. the Header Method).

After the retry request succeeds, you will receive a new Verification Notification as if the subscription was brand new, and you'll be able to try to respond correctly one more time.

Usually, we expect Production services to be able to verify a new subscription at the first attempt, so we decided to throttle this method to avoid its misusage. Every subscription will have a maximum of 5 verification attempts, and you'll be able to perform only one retry every 10 minutes. After the fifth failed attempt, the subscription will be lost forever and you'll need to create a new one from scratch.

----

## Notifications
The webhooks are a pattern to deliver notifications between applications via HTTP endpoints, allowing external applications to register an HTTP endpoint to which notifications are delivered. After a Subscription has been established, notifications will start being sent to the URL you specified; on this page, we'll explain what's the format and how to manage them correctly.
☁️  CloudEvents
Our implementation is loosely compliant with the CloudEvents Specification. You don't need to know the specification in detail to use our webhooks, but if you're curious you can check the dedicated page.
🎯  Update the Target
If you were able to create and verify your Subscription, this means that you already have a target and that you managed the GET request correctly. The Notifications events will be sent to the same endpoint, but in this case, you'll be dealing with POST requests. Below you can find the structure of the notification and how to manage it.
📮  The Notification
A Notification is an HTTP POST request sent to the Target's endpoint by our Webhooks system; each notification is compliant with the CloudEvents Core specification and with the HTTP Protocol Binding (Binary and Structured Content Modes). Our Webhooks will handle a certain number of Notification Types, that can be categorized as follows:
Verification Notifications
Welcome Notifications
Event Notifications
The following structure will be applied to the Welcome and Event Notifications, while the Verification Notifications are explained in the Subscriptions page. During the subscription process you can specify if the notifications you will receive will be in the Binary or Structured content mode, below you can find examples of both the options:
```
curl --location --request POST 'https://example.com/notifications' \
--header 'User-Agent: FattureInCloud/API-WEBHOOK' \
--header 'ce-type: it.fattureincloud.webhooks.entities.clients.create' \
--header 'ce-time: 2023-04-04T12:54:21+02:00' \
--header 'ce-subject: company:108061' \
--header 'ce-specversion: 1.0' \
--header 'ce-source: https://api-v2.fattureincloud.it' \
--header 'ce-id: 198:f059b211-24f4-44ab-9859-b1613a9a0712' \
--header 'Authorization: Bearer SIGNATURE' \
--header 'Connection: close' \
--header 'Content-Type: application/json' \
--data-raw '{
  "data": {
    "ids": [
      3062300
    ]
  }
}'
```
```
curl --location --request POST 'https://example.com/notifications' \
--header 'User-Agent: FattureInCloud/API-WEBHOOK' \
--header 'Authorization: Bearer SIGNATURE' \
--header 'Connection: close' \
--header 'Content-Type: application/cloudevents+json' \
--data-raw '{
  "id": "198:f059b211-24f4-44ab-9859-b1613a9a0712",
  "source": "https://api-v2.fattureincloud.it",
  "specversion": "1.0",
  "type": "it.fattureincloud.webhooks.entities.clients.create",
  "subject": "company:108061",
  "time": "2023-04-04T12:54:21+02:00",
  "datacontenttype": "application/json",
  "data": {
    "ids": [
      3062300
    ]
  }
}'
```
```
POST /notifications HTTP/1.1
Host: example.com
User-Agent: FattureInCloud/API-WEBHOOK
ce-type: it.fattureincloud.webhooks.entities.clients.create
ce-time: 2023-04-04T12:54:21+02:00
ce-subject: company:108061
ce-specversion: 1.0
ce-source: https://api-v2.fattureincloud.it
ce-id: 198:f059b211-24f4-44ab-9859-b1613a9a0712
Authorization: Bearer SIGNATURE
Connection: close
Content-Type: application/json
Content-Length: 52

{
  "data": {
    "ids": [
      3062300
    ]
  }
}
```
```
POST /notifications HTTP/1.1
Host: example.com
User-Agent: FattureInCloud/API-WEBHOOK
Authorization: Bearer SIGNATURE
Connection: close
Content-Type: application/json
Content-Length: 301

{
  "id": "198:f059b211-24f4-44ab-9859-b1613a9a0712",
  "source": "https://api-v2.fattureincloud.it",
  "specversion": "1.0",
  "type": "it.fattureincloud.webhooks.entities.clients.create",
  "subject": "company:108061",
  "time": "2023-04-04T12:54:21+02:00",
  "datacontenttype": "application/json",
  "data": {
    "ids": [
      3062300
    ]
  }
}
```

The CloudEvents Attributes are inserted as HTTP Headers prefixed by ce- in the Binary Mode, while in the Structured Mode they are available in the request body, the following list explains their values:
type: the Type of the Notification; it identifies the action that was performed on the resource
time: the timestamp of when the occurrence happened, in RFC 3339 format
subject: the subject of the event, for now, the subject will always be the company of the resource; it has the format "TYPE:ID" where TYPE is "company" and ID is the Company ID
specversion: the version of the CloudEvents specification that the event uses (at this moment "1.0")
source: the context in which an event happened; it has value "https://api-v2.fattureincloud.it"
id: a unique string identifier for the notification
There are some additional Headers:
Authorization: it will contain a token that should be used to verify that the notification was issued by our system; see the dedicated section for more info
Connection: it is set as "close" and indicates that our system will close the HTTP connection after the completion of the response
User-Agent: even if the User-Agent can't be trusted since it can be set to an arbitrary value, it is used to indicate that the sender is the Fatture in Cloud Webhooks System
The Body of the Notification will contain a simple JSON object, where the data.ids field will contain the array of the resources' IDs modified by this event; most of the time it will contain only a single ID, but a single event might modify more resources (for example, in an import of multiple clients via Excel file) and in this case the array could contain more than one identifier.

As you can see, the body won't include any possibly sensitive information about the resources, to avoid any possible data leak due to the misuse of our Webhooks. We expect your application to be able to retrieve the updated status of the resource using the related Get API method passing the resource ID as a parameter; the API methods are authenticated, so it is required to possess the permissions on the resource to perform the expected operations.
🚴  How should I use it?
The behavior that your application must adopt when a notification is received is up to you; the notification's objective is to notify you that something has happened and that you could need to do something to react to the event, but we don't know the scope of your application.

Even in this scenario, we still require you to respect some simple rules that are necessary to provide the best performance to all the webhooks users; if you don't respect these rules your subscriptions will most probably be deactivated, so make sure to read it carefully!
🏎  Fast is better!
First, you should answer fast to our request. Our Notification system has a short timeout time: if your application will answer too slowly, it will be handled as an error on your notification, and it will be managed accordingly.

Since it's impossible to predict how fast your system will complete, you should process the notification asynchronously. For example, you could insert the notification in a queue, use some library to create background jobs, or you could simply store the notification in a dedicated database table and process the records periodically using a cron job.

In the Additional Resources you can find a list of links that you could use to manage your applications properly.
🛡  Defend yourself!
When you try to create a new subscription, we check if the URL you exposed is using HTTPS. We won't accept your subscription if you're using HTTP.

Also, to avoid possible data leaks we don't share sensitive information in the notification body, but just the identifier of the resources involved in the event. To access the actual state of the resource you'll need to perform an API call after you received the notification.
📤  What should I answer?
If our system obtains a Success Status from your target endpoint, the message will be considered as successfully delivered and we will not retry to send it. The list of the Success Statuses can be found below:

HTTP Status Code	HTTP Status	Meaning
200	OK	The request has succeeded.
201	Created	The request has succeeded and led to the creation of a resource.
202	Accepted	The request has been accepted for processing, but the processing has not been completed.
204	No Content	The request has succeeded, but the client doesn't need to navigate away from its current page.
102	Processing	The request has been received and the server is working on it.

If our system will receive an Error status, the adopted behavior depends on the specific status code that was returned.

If we receive a Retryable Error Status, we will try again to send the notification for a total of four attempts, with a growing interval between a try and the next one. If a notification receives a Retryable Error even at the fourth attempt, it will be discarded and no further delivery attempts will be performed for that same notification.

We consider all the Server Error Responses (e.g. the 500-599 status codes) as Retryable errors.

Every status not included in the lists above will be considered an Unretryable Error Status. In this case, the Notification will be immediately discarded and our system will not try to deliver it again; this is valid even for the redelivery attempts, so if for example the second attempt fails with an Unretryable Error there won't be a third attempt.

Please, note that we'll manage also the Redirection Messages (e.g. the 300-399 status codes) as errors.

A special exception is the 410 Gone status code: in this case, we consider that your application was permanently dismissed or that it wants to get rid of the active subscription; if we receive this kind of error we'll immediately remove the subscription as explained in the Subscriptions page.
♊  Beware of the twins!
Our system adopts an At-Least-Once Delivery Policy, this means that the Notifications will be sent at least once, but they could also occasionally be delivered more times even if the first delivery was successful. If this could be an issue for your application, we suggest you use the Notification ID to filter out the twin messages and avoid reprocessing them a second time.
🍡  What about the order?
Our system will deliver the events in generation order most of the time. In some rare cases, an event could break the order and be delivered after another one that was generated later; for example, if it is necessary to redeliver a message after a retryable error. If needed, you can use the timestamp included in the CloudEvents attributes to find out when an event occurred and adopt the correct strategy.
👮  Validate the requests!
Your Target endpoint unfortunately will not be protected by an authentication mechanism, so it could be potentially abused by a malicious third party. If you want to be sure to protect your application from potential attacks, you can use the token included in the Authorization header to verify if the message was issued by Fatture in Cloud.

The header value will contain (after the "Bearer" prefix) a JWT token that you will be able to verify by using the Public Key published on this page. The token will be signed by using a Private Key used only for this purpose and not shared externally, so you will be sure that if the token is valid the request was legit.

The Public Key, Base 64 encoded, can be found here:
```
LS0tLS1CRUdJTiBQVUJMSUMgS0VZLS0tLS0KTUZrd0V3WUhLb1pJemowQ0FRWUlLb1pJemowREFRY0RRZ0FFL1JvSElqZ1k3aGZYZlk1cC9KeStLL0ZndU1aNAozVHZaOXQ0ZU43K2t4UTBNSnpLdG93djRDY1lURnFyQm03aE1CNVpXS25xTHoyNEQ2bFFqU0wwWXN3PT0KLS0tLS1FTkQgUFVCTElDIEtFWS0tLS0tCg==
```
The JWT's payload will contain the following claims:

Claim Key	Claim Name	Meaning
jti	JWT ID	The ID of the JWT (the same ID used for the message in the CloudEvents Attributes)
iss	Issuer	Who generated the JWT ("https://api-v2.fattureincloud.it")
exp	Expiration time	When the JWT will expire (3 hours from now)
sub	Subject	The subject, it can also be found in the CloudEvents Attributes
aud	Audience	A list containing the Target's Endpoint
iat	Issued at	The timestamp of the moment when the token was generated
aid	Application ID	The Fatture in Cloud's Application ID related to the Subscription

After you verified the token, you can check the claims to see if they match the message content. You can verify the JWT token by using the code below (replace the Public Key with the one provided above):
```
// https://packagist.org/packages/firebase/php-jwt

<?php

require('vendor/autoload.php');

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

echo verifyAccessToken('TOKEN');

function verifyAccessToken(string $accessToken)
{
    try {
        $pub = base64_decode('FIC_PUBLIC_KEY');
        JWT::decode($accessToken, new Key($pub, 'ES256'));
    } catch (Exception $e) {
        return false;
    }
    return true;
}

```

⏳  Subscriptions expire!
To avoid sending notifications to faulty or dismissed systems, we implemented an Expiration Mechanism on our Subscriptions: if a Subscription expires, our system will stop sending Notifications and you'll need to create (and verify) a new Subscription if you're still interested in being synchronized.

Expiration is triggered when our system retrieves an error from the Target system, so you will not have problems if you'll implement your service properly. Check the related page to learn more about it.
📚  Additional Resources
Below you can find a non-exhaustive list of tools you can use to manage the notifications asynchronously:

✉️  Standalone services
ActiveMQ
RabbitMQ
Redis Lists
Google Cloud Tasks
Amazon SQS

🛠  Background Libraries
Laravel Queues (PHP)
Bernard (PHP)
gocraft/work (Go)
RQ (Python)
Resque (Ruby)
BullMQ (JavaScript / Typescript)
Hangfire (C#)
JobRunr (Java)

----

# Notification Types
🎉  Event Types
The Event Types we support, with the scopes needed to subscribe, are:

Event Type	Description	Scopes
it.fattureincloud.webhooks.issued_documents.invoices.create	Issued Documents - Invoices Creation	issued_documents.invoices
it.fattureincloud.webhooks.issued_documents.invoices.update	Issued Documents - Invoices Modification	issued_documents.invoices
it.fattureincloud.webhooks.issued_documents.invoices.delete	Issued Documents - Invoices Deletion	issued_documents.invoices
it.fattureincloud.webhooks.issued_documents.quotes.create	Issued Documents - Quotes Creation	issued_documents.quotes
it.fattureincloud.webhooks.issued_documents.quotes.update	Issued Documents - Quotes Modification	issued_documents.quotes
it.fattureincloud.webhooks.issued_documents.quotes.delete	Issued Documents - Quotes Deletion	issued_documents.quotes
it.fattureincloud.webhooks.issued_documents.proformas.create	Issued Documents - Proformas Creation	issued_documents.proformas
it.fattureincloud.webhooks.issued_documents.proformas.update	Issued Documents - Proformas Modification	issued_documents.proformas
it.fattureincloud.webhooks.issued_documents.proformas.delete	Issued Documents - Proformas Deletion	issued_documents.proformas
it.fattureincloud.webhooks.issued_documents.receipts.create	Issued Documents - Receipts Creation	issued_documents.receipts
it.fattureincloud.webhooks.issued_documents.receipts.update	Issued Documents - Receipts Modification	issued_documents.receipts
it.fattureincloud.webhooks.issued_documents.receipts.delete	Issued Documents - Receipts Deletion	issued_documents.receipts
it.fattureincloud.webhooks.issued_documents.delivery_notes.create	Issued Documents - Delivery Notes Creation	issued_documents.delivery_notes
it.fattureincloud.webhooks.issued_documents.delivery_notes.update	Issued Documents - Delivery Notes Modification	issued_documents.delivery_notes
it.fattureincloud.webhooks.issued_documents.delivery_notes.delete	Issued Documents - Delivery Notes Deletion	issued_documents.delivery_notes
it.fattureincloud.webhooks.issued_documents.credit_notes.create	Issued Documents - Credit Notes Creation	issued_documents.credit_notes
it.fattureincloud.webhooks.issued_documents.credit_notes.update	Issued Documents - Credit Notes Modification	issued_documents.credit_notes
it.fattureincloud.webhooks.issued_documents.credit_notes.delete	Issued Documents - Credit Notes Deletion	issued_documents.credit_notes
it.fattureincloud.webhooks.issued_documents.orders.create	Issued Documents - Orders Creation	issued_documents.orders
it.fattureincloud.webhooks.issued_documents.orders.update	Issued Documents - Orders Modification	issued_documents.orders
it.fattureincloud.webhooks.issued_documents.orders.delete	Issued Documents - Orders Deletion	issued_documents.orders
it.fattureincloud.webhooks.issued_documents.work_reports.create	Issued Documents - Work Reports Creation	issued_documents.work_reports
it.fattureincloud.webhooks.issued_documents.work_reports.update	Issued Documents - Work Reports Modification	issued_documents.work_reports
it.fattureincloud.webhooks.issued_documents.work_reports.delete	Issued Documents - Work Reports Deletion	issued_documents.work_reports
it.fattureincloud.webhooks.issued_documents.supplier_orders.create	Issued Documents - Supplier Orders Creation	issued_documents.supplier_orders
it.fattureincloud.webhooks.issued_documents.supplier_orders.update	Issued Documents - Supplier Orders Modification	issued_documents.supplier_orders
it.fattureincloud.webhooks.issued_documents.supplier_orders.delete	Issued Documents - Supplier Orders Deletion	issued_documents.supplier_orders
it.fattureincloud.webhooks.issued_documents.self_invoices.create	Issued Documents - Self Invoices Creation	issued_documents.self_invoices
it.fattureincloud.webhooks.issued_documents.self_invoices.update	Issued Documents - Self Invoices Modification	issued_documents.self_invoices
it.fattureincloud.webhooks.issued_documents.self_invoices.delete	Issued Documents - Self Invoices Deletion	issued_documents.self_invoices
it.fattureincloud.webhooks.received_documents.create	Received Documents Creation	received_documents
it.fattureincloud.webhooks.received_documents.update	Received Documents Modification	received_documents
it.fattureincloud.webhooks.received_documents.delete	Received Documents Deletion	received_documents
it.fattureincloud.webhooks.receipts.create	Receipts Creation	receipts
it.fattureincloud.webhooks.receipts.update	Receipts Modification	receipts
it.fattureincloud.webhooks.receipts.delete	Receipts Deletion	receipts
it.fattureincloud.webhooks.taxes.create	Taxes Creation	taxes
it.fattureincloud.webhooks.taxes.update	Taxes Modification	taxes
it.fattureincloud.webhooks.taxes.delete	Taxes Deletion	taxes
it.fattureincloud.webhooks.cashbook.create	Cashbook Creation	cashbook
it.fattureincloud.webhooks.cashbook.update	Cashbook Modification	cashbook
it.fattureincloud.webhooks.cashbook.delete	Cashbook Deletion	cashbook
it.fattureincloud.webhooks.archive_documents.create	Archive Documents Creation	archive
it.fattureincloud.webhooks.archive_documents.update	Archive Documents Modification	archive
it.fattureincloud.webhooks.archive_documents.delete	Archive Documents Deletion	archive
it.fattureincloud.webhooks.products.create	Products Creation	products
it.fattureincloud.webhooks.products.update	Products Modification	products
it.fattureincloud.webhooks.products.delete	Products Deletion	products
it.fattureincloud.webhooks.products.stock_update	Products Stock Modification	products
stock
it.fattureincloud.webhooks.entities.clients.create	Clients Creation	entity.clients
it.fattureincloud.webhooks.entities.clients.update	Clients Modification	entity.clients
it.fattureincloud.webhooks.entities.clients.delete	Clients Deletion	entity.clients
it.fattureincloud.webhooks.entities.suppliers.create	Suppliers Creation	entity.suppliers
it.fattureincloud.webhooks.entities.suppliers.update	Suppliers Modification	entity.suppliers
it.fattureincloud.webhooks.entities.suppliers.delete	Suppliers Deletion	entity.suppliers
it.fattureincloud.webhooks.issued_documents.e_invoices.status_update	Status change on issued e-invoices	issued_documents.invoices
issued_documents.credit_notes
it.fattureincloud.webhooks.received_documents.e_invoices.receive	E-invoice Reception	received_documents
it.fattureincloud.webhooks.issued_documents.invoices.email_sent	Invoice sent through email	issued_documents.invoices
it.fattureincloud.webhooks.issued_documents.quotes.email_sent	Quote sent through email	issued_documents.quote
it.fattureincloud.webhooks.issued_documents.proformas.email_sent	Proforma sent through email	issued_documents.proformas
it.fattureincloud.webhooks.issued_documents.receipts.email_sent	Receipt sent through email	issued_documents.receipts
it.fattureincloud.webhooks.issued_documents.delivery_notes.email_sent	Delivery note sent through email	issued_documents.delivery_notes
it.fattureincloud.webhooks.issued_documents.credit_notes.email_sent	Credit note sent through email	issued_documents.credit_notes
it.fattureincloud.webhooks.issued_documents.orders.email_sent	Order sent through email	issued_documents.orders
it.fattureincloud.webhooks.issued_documents.work_reports.email_sent	Work report sent through email	issued_documents.work_reports
it.fattureincloud.webhooks.issued_documents.supplier_orders.email_sent	Supplier order sent through email	issued_documents.supplier_orders
it.fattureincloud.webhooks.issued_documents.self_invoices.email_sent	Self invoice sent through email	issued_documents.self_invoices
Please, note that to subscribe to a specific event type your Access Token must be authorized on all the scopes included in the Scopes column; as you can see we didn't specify the actual scopes but only their families, because in every case you just need Read permissions to subscribe to an event.
🚌  Group Types
The Group Types are special event types that can be used while creating a new subscription. They are not real types, but a way to subscribe to multiple events with a single entry in the subscription request; this means that a Group Type requires to have all the permissions connected to the related Event Types, otherwise the subscription will be rejected.

Here you can find the list of Group Types and the related Event Types.

Event Type	Events List
it.fattureincloud.webhooks.issued_documents.all.create	it.fattureincloud.webhooks.issued_documents.invoices.create
it.fattureincloud.webhooks.issued_documents.quotes.create
it.fattureincloud.webhooks.issued_documents.proformas.create
it.fattureincloud.webhooks.issued_documents.receipts.create
it.fattureincloud.webhooks.issued_documents.delivery_notes.create
it.fattureincloud.webhooks.issued_documents.credit_notes.create
it.fattureincloud.webhooks.issued_documents.orders.create
it.fattureincloud.webhooks.issued_documents.work_reports.create
it.fattureincloud.webhooks.issued_documents.supplier_orders.create
it.fattureincloud.webhooks.issued_documents.self_invoices.create
it.fattureincloud.webhooks.issued_documents.all.update	it.fattureincloud.webhooks.issued_documents.invoices.update
it.fattureincloud.webhooks.issued_documents.quotes.update
it.fattureincloud.webhooks.issued_documents.proformas.update
it.fattureincloud.webhooks.issued_documents.receipts.update
it.fattureincloud.webhooks.issued_documents.delivery_notes.update
it.fattureincloud.webhooks.issued_documents.credit_notes.update
it.fattureincloud.webhooks.issued_documents.orders.update
it.fattureincloud.webhooks.issued_documents.work_reports.update
it.fattureincloud.webhooks.issued_documents.supplier_orders.update
it.fattureincloud.webhooks.issued_documents.self_invoices.update
it.fattureincloud.webhooks.issued_documents.all.delete	it.fattureincloud.webhooks.issued_documents.invoices.delete
it.fattureincloud.webhooks.issued_documents.quotes.delete
it.fattureincloud.webhooks.issued_documents.proformas.delete
it.fattureincloud.webhooks.issued_documents.receipts.delete
it.fattureincloud.webhooks.issued_documents.delivery_notes.delete
it.fattureincloud.webhooks.issued_documents.credit_notes.delete
it.fattureincloud.webhooks.issued_documents.orders.delete
it.fattureincloud.webhooks.issued_documents.work_reports.delete
it.fattureincloud.webhooks.issued_documents.supplier_orders.delete
it.fattureincloud.webhooks.issued_documents.self_invoices.delete
it.fattureincloud.webhooks.entities.all.create	it.fattureincloud.webhooks.entities.clients.create
it.fattureincloud.webhooks.entities.suppliers.create
it.fattureincloud.webhooks.entities.all.update	it.fattureincloud.webhooks.entities.clients.update
it.fattureincloud.webhooks.entities.suppliers.update
it.fattureincloud.webhooks.entities.all.delete	it.fattureincloud.webhooks.entities.clients.delete
it.fattureincloud.webhooks.entities.suppliers.delete
it.fattureincloud.webhooks.issued_documents.all.email_sent	it.fattureincloud.webhooks.issued_documents.invoices.email_sent
it.fattureincloud.webhooks.issued_documents.quotes.email_sent
it.fattureincloud.webhooks.issued_documents.proformas.email_sent
it.fattureincloud.webhooks.issued_documents.receipts.email_sent
it.fattureincloud.webhooks.issued_documents.delivery_notes.email_sent
it.fattureincloud.webhooks.issued_documents.credit_notes.email_sent
it.fattureincloud.webhooks.issued_documents.orders.email_sent
it.fattureincloud.webhooks.issued_documents.work_reports.email_sent
it.fattureincloud.webhooks.issued_documents.self_invoices.email_sent

Please, note that the Group Types will be converted to the Event Types while creating the subscription, so the GET requests will return the Event Types and not the original Group Types.

----

## Subscription Expiration
Subscription Expiration
In some cases, your Subscription could expire, this means that our system will stop sending Notifications, and you'll need to create (and verify) a new Subscription if you're still interested in receiving Notifications. Below we'll explain why we decided to implement it, how it works, and how to avoid expiration on your subscriptions.
🙏  Why do you expire?
Our webhooks will need to send notifications to external systems developed by teams we don't have direct contact with, so we need some kind of mechanism to avoid sending unuseful notifications in case a system is faulty (e.g. it is unable to process our notifications properly) or if the system was dismissed without deleting the subscription.

Our Expiration mechanism is triggered by errors: when a new subscription is created it doesn't have an expiration date, so if you manage the notifications correctly you'll never have to worry about it.
🍅  When do you expire?
The expiration is triggered when one of these conditions occurs:
An Unretryable Error is returned
A Notification fails with Retryable Errors, and all the Retries fail
When the expiration is triggered, the Expiration Date is set to 10 days since the first error occurs; until that moment, you will keep receiving messages like usual. The expiration date doesn't change if your system keeps returning error statuses, so if your system is facing some issue you have the time to correct it and make your service recover.

Even if the 10 days are terminated, you can still save your Subscription: our system triggers the deletion only when we receive the first error after the Expiration Date is passed.
👨‍🔬  How can I save my subscription?
The Expiration Date will be reset (and the subscription will not expire) if your system can return a Success status to at least one notification; this means that you just need to show that your system is back to business.

Even if the 10 days are terminated, you can still save your Subscription: if you can to send a Success Status before deletion the Subscription will be saved, even if it occurred after 10 days.
🗑  My subscription expired. What can I do?
If you were able to fix your service, but the subscription expired in the meanwhile, you can just create a new one from scratch to start being notified again.