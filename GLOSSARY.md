# PromptEdit image generation

Users pay to generate images through Pixelforge; the operator resells each generation at a markup.

## Language

### People

**User**:
A registered person who submits generations and pays for them.
_Avoid_: Customer, client, account

**Operator**:
The party running this app, who pays Pixelforge and earns the markup.
_Avoid_: Admin, owner

**Provider**:
Pixelforge, the service that produces the images and bills the operator.
_Avoid_: Vendor, upstream, API

### Generation

**Generation**:
One request by a user to produce one image, from submission to its final status.
_Avoid_: Job, task, request, image

**Waiting generation**:
A generation the provider has not accepted yet.
_Avoid_: Pending, unsent

**Accepted generation**:
A generation the provider has acknowledged and given its own identifier.
_Avoid_: Created, submitted

**History**:
A user's own generations, newest first.
_Avoid_: Feed, gallery, list

**Local failure**:
A failure decided by this app before the provider accepted the generation. It is provisional: any state reported by the provider for that generation replaces it.
_Avoid_: Timeout, internal failure

**Provider state**:
What the provider reports about a generation at one moment, whichever way it reaches us.
_Avoid_: Payload, snapshot, update

**Webhook event**:
One notification from the provider carrying a provider state. The same event may be delivered more than once.
_Avoid_: Callback, notification, hook

### Money

**Rates**:
The provider's price per token of each type, fixed for a generation when the provider accepts it.
_Avoid_: Prices, tariffs

**Usage**:
The token counts the provider reports for a succeeded generation.
_Avoid_: Consumption, tokens spent

**Provider cost**:
What the provider bills the operator for a generation: its usage at its own rates.
_Avoid_: Actual cost, price, spend

**Markup**:
The multiplier turning provider cost into user charge, fixed for a generation at submission.
_Avoid_: Margin, fee, commission

**User charge**:
What the user pays for a generation: provider cost with the markup applied.
_Avoid_: Price, cost, bill

**Estimate**:
The range of possible user charges shown before submission. Its maximum is what the user agrees to.
_Avoid_: Quote, preview price
